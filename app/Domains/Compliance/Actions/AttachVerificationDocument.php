<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Client\Exceptions\RejectedUpload;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Compliance\Enums\DocumentStatus;
use App\Domains\Compliance\Enums\DocumentType;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Attaches an evidence file to a verification profile (spec §11, §55).
 *
 * The file is validated and written to private storage first; only if that
 * succeeds is a row created. A rejected upload therefore leaves neither a
 * database row nor an orphaned object.
 */
final class AttachVerificationDocument
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws RejectedUpload when the file fails validation
     */
    public function handle(
        VerificationProfile $profile,
        UploadedFile $file,
        DocumentType $type,
        User $uploader,
    ): VerificationDocument {
        $organization = $profile->organization()->firstOrFail();

        $stored = $this->storage->store($file, $organization, $type->value);

        try {
            return DB::transaction(function () use ($profile, $stored, $type, $uploader, $organization): VerificationDocument {
                $document = new VerificationDocument([
                    'type' => $type,
                    'disk' => $stored->disk,
                    'path' => $stored->path,
                    'original_filename' => $stored->originalFilename,
                    'media_type' => $stored->mediaType->value,
                    'size_bytes' => $stored->sizeInBytes,
                    'checksum' => $stored->checksum,
                    'width' => $stored->width,
                    'height' => $stored->height,
                    'status' => DocumentStatus::Pending,
                    'uploaded_by' => $uploader->getKey(),
                ]);

                $document->verification_profile_id = $profile->getKey();
                $document->organization_id = $organization->getKey();
                $document->tenant_id = $profile->tenant_id;
                $document->save();

                $this->audit->record(
                    action: AuditAction::VerificationDocumentUploaded,
                    resource: $document,
                    after: [
                        'type' => $type->value,
                        'filename' => $stored->originalFilename,
                        'size_bytes' => $stored->sizeInBytes,
                    ],
                    organization: $organization,
                    actor: $uploader,
                );

                return $document;
            });
        } catch (\Throwable $exception) {
            // The bytes are already on disk; without the row nothing can ever
            // reference them, so remove them rather than leave them orphaned.
            $this->storage->delete($stored->path);

            throw $exception;
        }
    }
}
