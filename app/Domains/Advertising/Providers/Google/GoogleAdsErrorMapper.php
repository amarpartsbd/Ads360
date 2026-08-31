<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers\Google;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;

/**
 * Turns a Google Ads failure into something the platform can act on
 * (spec §29, §80).
 *
 * Google's error envelope is shaped differently from Meta's, and the
 * difference matters. Meta returns one error with a numeric code. Google
 * returns a `GoogleAdsFailure` containing a *list* of errors, each with an
 * `errorCode` that is a single-key object naming the error's family:
 *
 *     {"errorCode": {"campaignError": "DUPLICATE_NAME"}, "message": "..."}
 *     {"errorCode": {"quotaError": "RESOURCE_EXHAUSTED"}, "message": "..."}
 *
 * So the family — `authenticationError`, `quotaError`, `internalError` — is
 * the key, not the value, and one response can carry several. The mapping
 * takes the most consequential: an authentication failure among five errors
 * still means the client has to reconnect.
 *
 * As with Meta, the question being answered is not "what went wrong" but
 * "could trying again help". Retrying a quota error is correct. Retrying an
 * expired grant burns quota and delays telling the client. Retrying a policy
 * refusal is what §27 forbids outright.
 */
final class GoogleAdsErrorMapper
{
    /**
     * Error families that mean the grant is gone or was never good. A retry
     * with the same token cannot help.
     *
     * @var list<string>
     */
    private const AUTH_FAMILIES = ['authenticationError'];

    /**
     * Families that mean "slow down". Google expresses both per-minute
     * throttling and daily operation limits this way.
     *
     * @var list<string>
     */
    private const RATE_LIMIT_FAMILIES = ['quotaError'];

    /**
     * Families that mean Google is having a bad moment rather than refusing.
     *
     * @var list<string>
     */
    private const TRANSIENT_FAMILIES = ['internalError', 'requestError'];

    /**
     * Families that mean permission was not granted, or the customer is not
     * one this platform may act on.
     *
     * @var list<string>
     */
    private const PERMISSION_FAMILIES = ['authorizationError', 'headerError'];

    /**
     * gRPC status strings Google returns in `error.status`, for the cases
     * where the failure never reached the Ads layer and so carries no
     * GoogleAdsFailure at all.
     *
     * @var list<string>
     */
    private const TRANSIENT_STATUSES = ['INTERNAL', 'UNAVAILABLE', 'DEADLINE_EXCEEDED', 'ABORTED'];

    /**
     * `requestError` is listed as transient above because it usually means a
     * malformed or expired request the caller can re-issue — but two of its
     * values are the opposite, and mapping them as retryable would have the
     * queue hammer a request that can never succeed.
     *
     * @var list<string>
     */
    private const PERMANENT_REQUEST_ERRORS = ['UNKNOWN', 'RESOURCE_NAME_MALFORMED', 'BAD_RESOURCE_ID'];

    /**
     * @param  array<string, mixed>  $error  the decoded `error` object
     */
    public function map(array $error, int $status): ProviderUnavailable
    {
        $failures = $this->failures($error);
        $detail = $this->detail($error, $failures, $status);
        $families = $this->families($failures);

        if ($this->duplicateName($failures)) {
            return DuplicateResourceName::for('object', $detail);
        }

        if ($this->matches($families, self::AUTH_FAMILIES) || $status === 401) {
            return ProviderUnavailable::authenticationFailed(Provider::Google, $detail);
        }

        if ($this->matches($families, self::RATE_LIMIT_FAMILIES)
            || $status === 429
            || ($error['status'] ?? null) === 'RESOURCE_EXHAUSTED'
        ) {
            return ProviderUnavailable::rateLimited(Provider::Google, $detail);
        }

        if ($this->matches($families, self::PERMISSION_FAMILIES) || $status === 403) {
            return ProviderUnavailable::refused(
                Provider::Google,
                $detail,
                $this->permissionMessage($failures),
            );
        }

        if ($status >= 500
            || in_array((string) ($error['status'] ?? ''), self::TRANSIENT_STATUSES, true)
            || ($this->matches($families, self::TRANSIENT_FAMILIES) && ! $this->permanentRequestError($failures))
        ) {
            return ProviderUnavailable::transient(Provider::Google, $detail);
        }

        // Anything else Google says no to is a refusal on its own terms, and
        // is surfaced rather than worked around (§27).
        return ProviderUnavailable::refused(
            Provider::Google,
            $detail,
            $this->clientMessageFor($failures),
        );
    }

    /**
     * A failure that never produced a response at all. It says nothing about
     * whether the request was valid, so it is always transient.
     */
    public function transport(string $detail): ProviderUnavailable
    {
        return ProviderUnavailable::transient(Provider::Google, $detail);
    }

    /**
     * OAuth failures come from a different service with a different envelope:
     * `{"error": "invalid_grant", "error_description": "..."}`. They are
     * always about the grant, never about quota or policy.
     *
     * @param  array<string, mixed>  $body
     */
    public function oauth(array $body, int $status): ProviderUnavailable
    {
        $code = is_string($body['error'] ?? null) ? $body['error'] : 'unknown_error';
        $description = is_string($body['error_description'] ?? null) ? $body['error_description'] : '';

        $detail = trim("status={$status} error={$code} {$description}");

        if ($status >= 500) {
            return ProviderUnavailable::transient(Provider::Google, $detail);
        }

        // `invalid_grant` is the one that matters: a revoked or expired
        // refresh token. The client has to reconnect; nothing else will do.
        return ProviderUnavailable::authenticationFailed(Provider::Google, $detail);
    }

    /**
     * The individual errors inside a GoogleAdsFailure.
     *
     * @param  array<string, mixed>  $error
     * @return list<array<string, mixed>>
     */
    public function failures(array $error): array
    {
        $collected = [];

        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (! is_array($detail)) {
                continue;
            }

            foreach ((array) ($detail['errors'] ?? []) as $failure) {
                if (is_array($failure)) {
                    $collected[] = $failure;
                }
            }
        }

        return $collected;
    }

    /**
     * Google's own request id, which is the first thing their support asks
     * for — the equivalent of Meta's fbtrace_id.
     *
     * @param  array<string, mixed>  $error
     */
    public function requestId(array $error): ?string
    {
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (is_array($detail) && is_string($detail['requestId'] ?? null)) {
                return $detail['requestId'];
            }
        }

        return null;
    }

    /**
     * Whether this failure is Google refusing to create a second object with
     * a name that is already taken (Rule 17).
     *
     * Every resource family spells it slightly differently, which is why this
     * matches on the value rather than on the family.
     *
     * @param  list<array<string, mixed>>  $failures
     */
    public function duplicateName(array $failures): bool
    {
        foreach ($this->codes($failures) as $code) {
            if (str_contains($code, 'DUPLICATE_NAME') || str_contains($code, 'DUPLICATE_ADGROUP_NAME')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The error values, without their families: `DUPLICATE_NAME`,
     * `RESOURCE_EXHAUSTED`, and so on.
     *
     * @param  list<array<string, mixed>>  $failures
     * @return list<string>
     */
    public function codes(array $failures): array
    {
        $codes = [];

        foreach ($failures as $failure) {
            foreach ((array) ($failure['errorCode'] ?? []) as $value) {
                if (is_string($value)) {
                    $codes[] = $value;
                }
            }
        }

        return $codes;
    }

    /**
     * The error families: the *keys* of each `errorCode` object.
     *
     * @param  list<array<string, mixed>>  $failures
     * @return list<string>
     */
    private function families(array $failures): array
    {
        $families = [];

        foreach ($failures as $failure) {
            $code = $failure['errorCode'] ?? null;

            if (is_array($code)) {
                foreach (array_keys($code) as $family) {
                    $families[] = (string) $family;
                }
            }
        }

        return $families;
    }

    /**
     * @param  list<string>  $families
     * @param  list<string>  $wanted
     */
    private function matches(array $families, array $wanted): bool
    {
        return array_intersect($families, $wanted) !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     */
    private function permanentRequestError(array $failures): bool
    {
        return array_intersect($this->codes($failures), self::PERMANENT_REQUEST_ERRORS) !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     */
    private function permissionMessage(array $failures): string
    {
        $codes = $this->codes($failures);

        if (in_array('DEVELOPER_TOKEN_NOT_APPROVED', $codes, true)
            || in_array('DEVELOPER_TOKEN_PROHIBITED', $codes, true)
        ) {
            // Nothing the client can do. Naming it as their problem would send
            // them looking in the wrong place.
            return 'Our Google Ads access is not yet approved for this operation. Our team has been notified.';
        }

        return 'Google Ads has not granted us the permission this needs. '
            .'Reconnect the account and make sure every permission is allowed.';
    }

    /**
     * Plain language for the cases a client can genuinely act on. Everything
     * else gets the generic refusal, because inventing an explanation for an
     * error we do not recognise would be worse than admitting we do not know.
     *
     * @param  list<array<string, mixed>>  $failures
     */
    private function clientMessageFor(array $failures): ?string
    {
        foreach ($this->codes($failures) as $code) {
            $message = match ($code) {
                'CUSTOMER_NOT_ENABLED' => 'This Google Ads account is not active. Please check it in Google Ads.',
                'ACCOUNT_SUSPENDED' => 'Google has suspended this advertising account.',
                'BILLING_SETUP_REQUIRED', 'MISSING_PAYMENTS_ACCOUNT' => 'This Google Ads account has no billing set up yet.',
                'POLICY_FINDING', 'POLICY_VIOLATION' => 'Google declined this ad on policy grounds. Please review the wording and the destination page.',
                'CUSTOMER_NOT_ELIGIBLE' => 'Google does not consider this account eligible for what the campaign asks for.',
                default => null,
            };

            if ($message !== null) {
                return $message;
            }
        }

        return null;
    }

    /**
     * A log line with everything useful and nothing sensitive.
     *
     * @param  array<string, mixed>  $error
     * @param  list<array<string, mixed>>  $failures
     */
    private function detail(array $error, array $failures, int $status): string
    {
        $parts = array_filter([
            'status='.$status,
            isset($error['status']) ? 'grpc='.(string) $error['status'] : null,
            $this->codes($failures) !== [] ? 'codes='.implode(',', $this->codes($failures)) : null,
            ($id = $this->requestId($error)) !== null ? 'request_id='.$id : null,
            isset($error['message']) ? 'message='.(string) $error['message'] : null,
        ]);

        return implode(' ', $parts);
    }
}
