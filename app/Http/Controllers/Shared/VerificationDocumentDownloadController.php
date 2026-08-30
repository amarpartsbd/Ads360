<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Compliance\Models\VerificationDocument;
use App\Domains\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only route to a KYC document's bytes (spec §55).
 *
 * Both the client application and the administration area come through here,
 * so authorization and the audit record are written once rather than in two
 * places that could drift apart. Every successful read is recorded: who looked
 * at whose identity documents is exactly the sort of question an audit trail
 * exists to answer.
 */
final class VerificationDocumentDownloadController
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditRecorder $audit,
    ) {}

    public function __invoke(Request $request, VerificationDocument $document): Response
    {
        Gate::authorize('download', $document);

        if (! $this->storage->exists($document->path)) {
            abort(404, 'That document is no longer available.');
        }

        /** @var User $user */
        $user = $request->user();

        $this->audit->record(
            action: AuditAction::VerificationDocumentDownloaded,
            resource: $document,
            context: ['type' => $document->type->value, 'filename' => $document->original_filename],
            organization: $document->organization()->first(),
            actor: $user,
        );

        $stream = $this->storage->readStream($document->path);

        if ($stream === null) {
            abort(404, 'That document is no longer available.');
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $document->media_type,
            'Content-Length' => (string) $document->size_bytes,
            // Displayed inline so a reviewer can read a scan without saving it,
            // with the filename preserved for when they do save it.
            'Content-Disposition' => sprintf(
                'inline; filename="%s"',
                str_replace('"', '', $document->original_filename),
            ),
            // Belt and braces against a crafted file being interpreted as
            // markup by the browser.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; object-src 'none'",
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
