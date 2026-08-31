<?php

declare(strict_types=1);

namespace App\Domains\Integration\Services;

use App\Domains\Advertising\Providers\Meta\MetaConfig;
use Illuminate\Http\Request;

/**
 * Proves an inbound webhook really came from Meta (spec §52, §98).
 *
 * A webhook endpoint is a URL anyone on the internet can post to. Without this
 * check, anyone who learned the URL could tell the platform that a campaign
 * had spent money, or that an account had been disabled — and the platform
 * would act on it.
 *
 * Three details matter, and each is a way this is commonly got wrong:
 *
 *   - **The signature covers the raw body**, not the parsed one. Re-encoding
 *     the JSON before hashing produces a different byte sequence and a
 *     signature that never matches.
 *   - **The comparison is constant-time.** A normal string comparison returns
 *     faster the earlier it finds a difference, which over enough attempts
 *     tells an attacker the signature one byte at a time.
 *   - **A missing signature is a rejection**, never a pass. An endpoint that
 *     accepted unsigned payloads would make the whole check decorative.
 */
final class MetaWebhookVerifier
{
    private const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    private const SIGNATURE_PREFIX = 'sha256=';

    public function __construct(private readonly MetaConfig $config) {}

    /**
     * Whether this request carries a signature Meta could have produced.
     */
    public function verify(Request $request): bool
    {
        $provided = (string) $request->header(self::SIGNATURE_HEADER, '');

        if ($provided === '' || ! str_starts_with($provided, self::SIGNATURE_PREFIX)) {
            return false;
        }

        if ($this->config->appSecret === '') {
            // No secret configured means nothing can be verified, and an
            // unverifiable webhook is refused rather than trusted.
            return false;
        }

        $expected = self::SIGNATURE_PREFIX.hash_hmac(
            'sha256',
            // The raw body, exactly as it arrived.
            $request->getContent(),
            $this->config->appSecret,
        );

        return hash_equals($expected, $provided);
    }

    /**
     * The subscription handshake.
     *
     * Meta calls the endpoint once with a challenge when a subscription is
     * created, and expects the challenge echoed back — but only if the token
     * it sends matches the one configured. Returning the challenge without
     * checking the token would let anyone point their own Meta app at this
     * endpoint.
     */
    public function challenge(Request $request): ?string
    {
        if ($request->query('hub_mode') !== 'subscribe') {
            return null;
        }

        $token = (string) $request->query('hub_verify_token', '');
        $expected = $this->config->webhookVerifyToken;

        if ($expected === null || $token === '' || ! hash_equals($expected, $token)) {
            return null;
        }

        $challenge = $request->query('hub_challenge');

        return is_string($challenge) ? $challenge : null;
    }
}
