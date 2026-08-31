<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Campaign\Services\CreativeStorage;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds a file to a client's creative library (spec §23).
 *
 * Re-uploading a file the organization already has returns the existing row
 * rather than storing a second copy. Clients do re-upload the same image for
 * several campaigns, and the checksum makes that free.
 */
final class UploadCreative
{
    public function __construct(
        private readonly CreativeStorage $storage,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(
        UploadedFile $file,
        Organization $organization,
        User $actor,
        ?string $name = null,
    ): Creative {
        $stored = $this->storage->store($file, $organization);

        $existing = Creative::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('checksum', $stored['checksum'])
            ->first();

        if ($existing !== null) {
            // The bytes are already on disk under the existing row's path, so
            // the copy just written is redundant.
            $this->storage->delete($stored['path']);

            return $existing;
        }

        $creative = DB::transaction(function () use ($stored, $organization, $actor, $name, $file): Creative {
            $creative = new Creative([
                'organization_id' => $organization->getKey(),
                'name' => $name !== null && trim($name) !== ''
                    ? trim($name)
                    : $this->safeName($file),
                'type' => $stored['type'],
                'storage_path' => $stored['path'],
                'media_type' => $stored['media_type']->value,
                'byte_size' => $stored['byte_size'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'checksum' => $stored['checksum'],
                'status' => 'READY',
                'uploaded_by' => $actor->getKey(),
            ]);

            $creative->tenant_id = $organization->tenant_id;
            $creative->save();

            return $creative;
        });

        $this->audit->record(
            action: AuditAction::CreativeUploaded,
            resource: $creative,
            after: $creative->describe(),
            organization: $organization,
            actor: $actor,
        );

        return $creative;
    }

    public function delete(Creative $creative, User $actor): void
    {
        if ($creative->isInUse()) {
            throw new RuntimeException(
                'This file is used by an ad and cannot be removed. Remove it from the ad first.'
            );
        }

        $description = $creative->describe();
        $path = $creative->storage_path;

        DB::transaction(function () use ($creative): void {
            $creative->delete();
        });

        // Removed after the row, not before: a failed transaction would
        // otherwise leave a row pointing at bytes that no longer exist.
        $this->storage->delete($path);

        $this->audit->record(
            action: AuditAction::CreativeDeleted,
            resource: $creative,
            before: $description,
            actor: $actor,
        );
    }

    /** Never trusts the uploaded filename as a path or as markup. */
    private function safeName(UploadedFile $file): string
    {
        $base = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));

        $cleaned = preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $base);

        return mb_substr($cleaned === null || $cleaned === '' ? 'creative' : $cleaned, 0, 120);
    }
}
