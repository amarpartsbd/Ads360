<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Exceptions;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use RuntimeException;
use Throwable;

/**
 * A provider could not be reached, or refused an operation (spec §29, §80).
 *
 * Carries whether retrying could plausibly help, so the queue layer can tell a
 * transient network failure from a permanent refusal and stop hammering the
 * latter (spec §29: "Do not endlessly retry permanent failures").
 *
 * The message is for logs. `clientMessage()` is what a person should be shown.
 */
class ProviderUnavailable extends RuntimeException
{
    /*
     * Protected rather than private so a provider adapter can add a case of
     * its own — see the Google adapter's DuplicateResourceName. A subclass
     * still *is* a ProviderUnavailable, so a caller that has never heard of it
     * handles it correctly as a non-retryable refusal.
     */
    protected function __construct(
        public readonly Provider $provider,
        public readonly bool $retryable,
        public readonly string $clientMessage,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The provider is down or unreachable. Worth retrying. */
    public static function transient(Provider $provider, string $detail, ?Throwable $previous = null): self
    {
        return new self(
            $provider,
            true,
            "We could not reach {$provider->label()} just now. We will try again shortly.",
            "{$provider->value} transient failure: {$detail}",
            $previous,
        );
    }

    /** The provider is throttling us. Worth retrying, later. */
    public static function rateLimited(Provider $provider, string $detail): self
    {
        return new self(
            $provider,
            true,
            "{$provider->label()} is limiting how quickly we can act. This will resume automatically.",
            "{$provider->value} rate limited: {$detail}",
        );
    }

    /** The grant is gone. Retrying with the same token cannot help. */
    public static function authenticationFailed(Provider $provider, string $detail): self
    {
        return new self(
            $provider,
            false,
            "Your {$provider->connectionLabel()} connection has expired. Please reconnect your account.",
            "{$provider->value} authentication failure: {$detail}",
        );
    }

    /**
     * The provider refused on its own terms — a policy, a permission, an
     * unverified business. Never worked around; recorded and surfaced (§27).
     */
    public static function refused(Provider $provider, string $detail, ?string $clientMessage = null): self
    {
        return new self(
            $provider,
            false,
            $clientMessage ?? "{$provider->label()} declined this request. Please review your account with them.",
            "{$provider->value} refused the operation: {$detail}",
        );
    }

    public static function notSupported(Provider $provider, ProviderCapability|string $capability): self
    {
        $name = $capability instanceof ProviderCapability ? $capability->value : $capability;

        return new self(
            $provider,
            false,
            "{$provider->label()} does not support this yet.",
            "{$provider->value} does not implement [{$name}].",
        );
    }
}
