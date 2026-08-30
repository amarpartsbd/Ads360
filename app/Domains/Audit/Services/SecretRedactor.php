<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

/**
 * Removes secrets from data on its way into an audit record or a log line
 * (spec §51, §57, Rule 12).
 *
 * Matching is on the key name and is deliberately broad: a false positive costs
 * one redacted field in an audit trail, a false negative writes a credential to
 * durable storage.
 */
final class SecretRedactor
{
    public const REDACTED = '[redacted]';

    /**
     * Key fragments that mark a value as secret. Compared case-insensitively
     * against each key, as a substring.
     *
     * @var list<string>
     */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'access_key',
        'private_key',
        'client_secret',
        'authorization',
        'credential',
        'two_factor',
        'recovery_code',
        'signature',
        'cvv',
        'card_number',
        'pin',
        'otp',
        'session_id',
        'remember_token',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $result;
    }

    public function isSensitive(string $key): bool
    {
        $normalised = strtolower($key);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
