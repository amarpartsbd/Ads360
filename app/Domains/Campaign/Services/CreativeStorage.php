<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Services;

use App\Domains\Campaign\Enums\CreativeMediaType;
use App\Domains\Campaign\Enums\CreativeType;
use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Tenant\Models\Organization;
use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores uploaded creatives on the private disk (spec §23, §55).
 *
 * The same discipline as verification documents, for the same reason: an
 * upload is untrusted input that will later be served back to people.
 *
 *   - the file is identified by its leading bytes, and the extension only has
 *     to agree — a `.jpg` that is really a script is refused;
 *   - the stored path is random and reveals nothing about the uploader;
 *   - the disk is private, and nothing ever returns a public URL.
 */
final class CreativeStorage
{
    private const DISK = 'creatives';

    /**
     * Smallest edge a provider will accept without upscaling badly. Below this
     * the ad looks wrong, and a client is better told now than after approval.
     */
    public const MIN_IMAGE_DIMENSION = 600;

    /**
     * @return array{
     *     disk: string,
     *     path: string,
     *     media_type: CreativeMediaType,
     *     type: CreativeType,
     *     byte_size: int,
     *     checksum: string,
     *     width: int|null,
     *     height: int|null,
     * }
     *
     * @throws RejectedUpload
     */
    public function store(UploadedFile $file, Organization $organization): array
    {
        $mediaType = $this->identify($file);
        $type = $mediaType->creativeType();

        $size = $file->getSize();

        if ($size === false || $size === 0) {
            throw RejectedUpload::emptyFile();
        }

        if ($size > $type->maximumBytes()) {
            throw RejectedUpload::tooLarge($type->maximumBytes());
        }

        $dimensions = ['width' => null, 'height' => null];

        if ($mediaType->isImage()) {
            $dimensions = $this->imageDimensions($file);
        }

        $checksum = hash_file('sha256', $file->getRealPath());

        if ($checksum === false) {
            throw RejectedUpload::unreadable();
        }

        $path = $this->randomPath($organization, $mediaType);

        $this->disk()->putFileAs(dirname($path), $file, basename($path));

        return [
            'disk' => self::DISK,
            'path' => $path,
            'media_type' => $mediaType,
            'type' => $type,
            'byte_size' => $size,
            'checksum' => $checksum,
            ...$dimensions,
        ];
    }

    /**
     * @return resource
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

    public function supportsTemporaryUrls(): bool
    {
        return method_exists($this->disk(), 'temporaryUrl');
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt): string
    {
        return $this->disk()->temporaryUrl($path, $expiresAt);
    }

    /**
     * @throws RejectedUpload
     */
    private function identify(UploadedFile $file): CreativeMediaType
    {
        $claimed = CreativeMediaType::fromExtension($file->getClientOriginalExtension());

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

    /** Reads the leading bytes and matches them against the allow-list. */
    private function sniff(string $absolutePath): ?CreativeMediaType
    {
        // Suppressed deliberately: an unreadable temp file is a rejection to
        // report, not a PHP warning to surface.
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

        foreach (CreativeMediaType::cases() as $case) {
            foreach ($case->signatures() as $signature) {
                // The offset matters: MP4's marker sits after a length field,
                // so matching from byte zero would never find it.
                $window = substr($hex, $case->signatureOffset() * 2);

                if (str_starts_with($window, $signature)) {
                    return $case;
                }
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
     * Nothing from the upload contributes to the path. The organization's ULID
     * groups a tenant's files for lifecycle rules; the rest is random.
     */
    private function randomPath(Organization $organization, CreativeMediaType $mediaType): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            $organization->public_id,
            $mediaType->creativeType()->value === 'VIDEO' ? 'video' : 'image',
            Str::ulid()->toString().'-'.Str::random(24),
            $mediaType->extensions()[0],
        );
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
