<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a document a client attached before submitting.
 *
 * The row is soft-deleted and the object is removed from storage. Keeping the
 * row preserves the fact that a file existed and who removed it, which matters
 * if a submission is later disputed; keeping the bytes would not, and holding
 * identity documents longer than necessary is its own risk.
 */
final class RemoveVerificationDocument
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(VerificationDocument $document, User $actor): void
    {
        $path = $document->path;
        $organization = $document->organization()->first();

        DB::transaction(function () use ($document, $actor, $organization): void {
            $document->delete();

            $this->audit->record(
                action: AuditAction::VerificationDocumentDeleted,
                resource: $document,
                before: [
                    'type' => $document->type->value,
                    'filename' => $document->original_filename,
                ],
                organization: $organization,
                actor: $actor,
            );
        });

        $this->storage->delete($path);
    }
}
