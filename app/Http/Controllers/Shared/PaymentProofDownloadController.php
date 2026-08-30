<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shared;

use App\Domains\Client\Services\DocumentStorage;
use App\Domains\Payment\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof of payment (spec §55).
 *
 * Same shape as the KYC document route: authorized first, streamed from private
 * storage, never a public URL.
 */
final class PaymentProofDownloadController
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function __invoke(Request $request, Payment $payment): Response
    {
        Gate::authorize('downloadProof', $payment);

        if (! $payment->hasProof() || ! $this->storage->exists($payment->proof_path)) {
            abort(404, 'No proof of payment is attached to this deposit.');
        }

        $stream = $this->storage->readStream($payment->proof_path);

        if ($stream === null) {
            abort(404, 'No proof of payment is attached to this deposit.');
        }

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Disposition' => sprintf(
                'inline; filename="%s"',
                str_replace('"', '', (string) $payment->proof_filename),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; object-src 'none'",
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
