<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Meta;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;

/**
 * Turns a Meta error envelope into something the platform can act on
 * (spec §29, §80).
 *
 * The distinction that matters is not what went wrong but whether trying again
 * could help. Retrying a rate limit is correct; retrying an expired token
 * burns quota and delays telling the client to reconnect; retrying a policy
 * refusal is spec §27's explicit prohibition — a refusal is surfaced, never
 * worked around.
 *
 * Meta's own error text is kept for the log and never shown to a client:
 * "(#100) Invalid parameter" tells someone nothing they can act on.
 */
final class MetaErrorMapper
{
    /**
     * Codes that mean "slow down". Retrying later is the right response.
     *
     * @var list<int>
     */
    private const RATE_LIMIT_CODES = [4, 17, 32, 613, 80000, 80003, 80004];

    /**
     * Codes that mean the grant is gone. A retry with the same token cannot
     * help; the client has to reconnect.
     *
     * @var list<int>
     */
    private const AUTH_CODES = [102, 190, 458, 459, 460, 463, 464, 467];

    /**
     * Codes that mean Meta is having a bad moment rather than refusing.
     *
     * @var list<int>
     */
    private const TRANSIENT_CODES = [1, 2];

    /**
     * Codes that mean permission was not granted, or the object is not ours.
     *
     * @var list<int>
     */
    private const PERMISSION_CODES = [10, 200, 272, 294];

    /**
     * @param  array<string, mixed>  $error  the decoded `error` object
     */
    public function map(array $error, int $status): ProviderUnavailable
    {
        $code = (int) ($error['code'] ?? 0);
        $subcode = (int) ($error['error_subcode'] ?? 0);
        $detail = $this->detail($error, $status);

        if (in_array($code, self::RATE_LIMIT_CODES, true) || $status === 429) {
            return ProviderUnavailable::rateLimited(Provider::Meta, $detail);
        }

        if (in_array($code, self::AUTH_CODES, true)) {
            return ProviderUnavailable::authenticationFailed(Provider::Meta, $detail);
        }

        if (in_array($code, self::TRANSIENT_CODES, true) || $status >= 500) {
            return ProviderUnavailable::transient(Provider::Meta, $detail);
        }

        if (in_array($code, self::PERMISSION_CODES, true)) {
            return ProviderUnavailable::refused(
                Provider::Meta,
                $detail,
                'Meta has not granted us the permission this needs. '
                .'Reconnect the account and make sure every permission is allowed.',
            );
        }

        // Anything else Meta says no to is a refusal on its own terms.
        return ProviderUnavailable::refused(
            Provider::Meta,
            $detail,
            $this->clientMessageFor($code, $subcode, $error),
        );
    }

    /**
     * Whether a *transport* failure — no envelope at all — is worth retrying.
     * A connection that never completed says nothing about the request's
     * validity, so it is always transient.
     */
    public function transport(string $detail): ProviderUnavailable
    {
        return ProviderUnavailable::transient(Provider::Meta, $detail);
    }

    /**
     * Plain language for the cases a client can genuinely act on. Everything
     * else gets the generic refusal, because inventing an explanation for an
     * error we do not recognise would be worse than admitting we do not know.
     *
     * @param  array<string, mixed>  $error
     */
    private function clientMessageFor(int $code, int $subcode, array $error): ?string
    {
        $userTitle = $error['error_user_title'] ?? null;
        $userMessage = $error['error_user_msg'] ?? null;

        // Meta marks some messages as written for an end user. Those are
        // safe to pass through, and are better than anything we could write.
        if (is_string($userMessage) && trim($userMessage) !== '') {
            return is_string($userTitle) && trim($userTitle) !== ''
                ? trim($userTitle).': '.trim($userMessage)
                : trim($userMessage);
        }

        return match (true) {
            $code === 1487742 => 'This ad account has reached a spending limit set at Meta.',
            $code === 1885183 => 'Meta requires this ad account to complete verification before it can run ads.',
            $code === 1349125 => 'The advertising account is not able to run ads at the moment.',
            default => null,
        };
    }

    /**
     * A log line with everything useful and nothing sensitive. The trace id is
     * what Meta's support asks for first.
     *
     * @param  array<string, mixed>  $error
     */
    private function detail(array $error, int $status): string
    {
        $parts = array_filter([
            'status='.$status,
            isset($error['code']) ? 'code='.$error['code'] : null,
            isset($error['error_subcode']) ? 'subcode='.$error['error_subcode'] : null,
            isset($error['type']) ? 'type='.$error['type'] : null,
            isset($error['fbtrace_id']) ? 'trace='.$error['fbtrace_id'] : null,
            isset($error['message']) ? 'message='.$error['message'] : null,
        ]);

        return implode(' ', $parts);
    }
}
