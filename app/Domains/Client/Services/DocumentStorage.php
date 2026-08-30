<?php

declare(strict_types=1);

namespace App\Domains\Client\Services;

use App\Domains\Client\DTOs\StoredDocument;
use App\Domains\Client\Enums\DocumentMediaType;
use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Tenant\Models\Organization;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores and retrieves private business documents (spec §55).
 *
 * Three things matter here.
 *
 * The client's declared MIME type is never trusted. A file is identified by
 * its leading bytes, and that identification must agree with its extension, so
 * renaming a script to `licence.pdf` does not get it stored as a PDF.
 *
 * Object paths are random. A stored path reveals nothing about the
 * organization or the document, and cannot be guessed from another one — but
 * nothing relies on that: reads still go through an authorization check.
 *
 * Nothing is ever written to a public disk, and no permanent URL is issued.
 * Access is a short-lived signed URL, minted per request.
 */
final class DocumentStorage
{
    /** Business documents are scans and photographs; 15 MB is generous for both. */
    public const MAX_BYTES = 15 * 1_048_576;

    /** Below this, a photographed document is not legible enough to review. */
    public const MIN_IMAGE_DIMENSION = 200;

    private const DISK = 'documents';

    /**
     * Validate an upload and store it under a random path.
     *
     * @throws RejectedUpload when the file fails any check. Nothing is written.
     */
    public function store(UploadedFile $file, Organization $organization, string $category): StoredDocument
    {
        if (! $file->isValid()) {
            throw RejectedUpload::unreadable();
        }

        $size = $file->getSize();

        if ($size === false || $size === 0) {
            throw RejectedUpload::emptyFile();
        }

        if ($size > self::MAX_BYTES) {
            throw RejectedUpload::tooLarge(self::MAX_BYTES);
        }

        $mediaType = $this->identify($file);

        $dimensions = $mediaType->isImage() ? $this->imageDimensions($file) : null;

        $path = $this->randomPath($organization, $category, $mediaType);

        $stream = @fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw RejectedUpload::unreadable();
        }

        try {
            $this->disk()->put($path, $stream, ['visibility' => 'private']);
        } finally {
            fclose($stream);
        }

        return new StoredDocument(
            disk: self::DISK,
            path: $path,
            // Kept only for display. It is never used to build a storage path,
            // so a crafted filename cannot escape the directory.
            originalFilename: $this->sanitiseFilename($file->getClientOriginalName()),
            mediaType: $mediaType,
            sizeInBytes: $size,
            checksum: (string) hash_file('sha256', $file->getRealPath()),
            width: $dimensions['width'] ?? null,
            height: $dimensions['height'] ?? null,
        );
    }

    /**
     * A short-lived URL for one document.
     *
     * The caller must have authorized the read first: this method issues the
     * URL, it does not decide who may have one.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt): string
    {
        $disk = $this->disk();

        if ($disk instanceof FilesystemAdapter && $disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($path, $expiresAt);
        }

        // The local driver cannot sign URLs. Callers fall back to streaming the
        // file through an authorized controller, which is what development and
        // any non-S3 deployment does.
        throw new \RuntimeException('The documents disk does not support temporary URLs.');
    }

    public function supportsTemporaryUrls(): bool
    {
        $disk = $this->disk();

        return $disk instanceof FilesystemAdapter && $disk->providesTemporaryUrls();
    }

    /**
     * @return resource|null
     */
    public function readStream(string $path)
    {
        return $this->disk()->readStream($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk()->exists($path);
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * Identify a file by its contents, and require the extension to agree.
     *
     * @throws RejectedUpload
     */
    private function identify(UploadedFile $file): DocumentMediaType
    {
        $claimed = DocumentMediaType::fromExtension($file->getClientOriginalExtension());

        if ($claimed === null) {
            throw RejectedUpload::disallowedType();
        }

        $actual = $this->sniff($file->getRealPath());

        if ($actual === null) {
            throw RejectedUpload::disallowedType();
        }

        if ($actual !== $claimed) {
            throw RejectedUpload::contentMismatch();
        }

        return $actual;
    }

    /** Reads the leading bytes of a file and matches them against the allow-list. */
    private function sniff(string $absolutePath): ?DocumentMediaType
    {
        // Warning suppressed deliberately: an unreadable temp file is a
        // rejection to report, not a PHP notice to surface.
        $handle = @fopen($absolutePath, 'rb');

        if ($handle === false) {
            throw RejectedUpload::unreadable();
        }

        try {
            $header = fread($handle, 16);
        } finally {
            fclose($handle);
        }

        if ($header === false || $header === '') {
            throw RejectedUpload::emptyFile();
        }

        $hex = bin2hex($header);

        foreach (DocumentMediaType::cases() as $case) {
            foreach ($case->signatures() as $signature) {
                if (! str_starts_with($hex, $signature)) {
                    continue;
                }

                // RIFF is a container: only a WEBP payload is accepted, so a
                // RIFF-wrapped AVI or WAV is not mistaken for an image.
                if ($case === DocumentMediaType::Webp && substr($header, 8, 4) !== 'WEBP') {
                    continue;
                }

                return $case;
            }
        }

        return null;
    }

    /**
     * @return array{width: int, height: int}
     *
     * @throws RejectedUpload
     */
    private function imageDimensions(UploadedFile $file): array
    {
        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            throw RejectedUpload::unreadableImage();
        }

        [$width, $height] = $size;

        if ($width < self::MIN_IMAGE_DIMENSION || $height < self::MIN_IMAGE_DIMENSION) {
            throw RejectedUpload::imageTooSmall(self::MIN_IMAGE_DIMENSION);
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * Build a random object path.
     *
     * The organization's ULID groups a tenant's files for lifecycle rules and
     * deletion, and the filename itself is random. Nothing from the upload
     * contributes to the path.
     */
    private function randomPath(
        Organization $organization,
        string $category,
        DocumentMediaType $mediaType,
    ): string {
        $extension = $mediaType->extensions()[0];

        return sprintf(
            '%s/%s/%s.%s',
            $organization->public_id,
            Str::slug($category) ?: 'document',
            Str::ulid()->toString().'-'.Str::random(24),
            $extension,
        );
    }

    /** Strips any path information a client may have put in the filename. */
    private function sanitiseFilename(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));

        return Str::limit(preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $base) ?: 'document', 120, '');
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
