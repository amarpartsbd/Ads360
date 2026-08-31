<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Campaign\Services\CreativeStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only route to a creative's bytes (spec §55).
 *
 * The disk is private, so there is no URL to guess. Every download is
 * authorised and audited — these are files a client uploaded, and who looked
 * at them is worth knowing.
 */
final class CreativeDownloadController
{
    public function __construct(
        private readonly CreativeStorage $storage,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request, Creative $creative): Response|StreamedResponse
    {
        Gate::authorize('download', $creative);

        abort_unless($this->storage->exists($creative->storage_path), 404);

        $this->audit->record(
            action: AuditAction::CreativeDownloaded,
            resource: $creative,
            context: ['name' => $creative->name],
            actor: $request->user(),
        );

        // On object storage the bytes are handed over directly by a
        // short-lived signed URL; locally they stream through this process.
        if ($this->storage->supportsTemporaryUrls()) {
            return redirect()->away(
                $this->storage->temporaryUrl($creative->storage_path, Carbon::now()->addMinutes(5))
            );
        }

        return response()->stream(
            function () use ($creative): void {
                $stream = $this->storage->readStream($creative->storage_path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $creative->media_type,
                // Never inline: a browser rendering client-uploaded content in
                // our origin is how a stored file becomes a stored XSS.
                'Content-Disposition' => 'attachment; filename="'.addslashes($creative->name).'"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
