<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Services;

use App\Domains\Advertising\Contracts\AdvertisingProvider;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Providers\Meta\MetaAdvertisingProvider;
use App\Domains\Advertising\Providers\Meta\MetaConfig;
use App\Domains\Advertising\Providers\Meta\MetaErrorMapper;
use App\Domains\Advertising\Providers\Meta\MetaGraphClient;
use App\Domains\Advertising\Providers\MockGoogleAdvertisingProvider;
use App\Domains\Advertising\Providers\MockMetaAdvertisingProvider;
use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the adapter for a provider (spec §26, §95).
 *
 * Which implementation answers is a configuration decision, not a code one:
 * `ADVERTISING_DRIVER=mock` gives the sandbox adapters, and a live driver gives
 * the real ones. Nothing above this class knows or cares.
 *
 * Adapters are memoised per request so a test can reach in, tell the mock to
 * fail, and have the code under test get the same instance back.
 */
final class ProviderManager
{
    /** @var array<string, AdvertisingProvider> */
    private array $resolved = [];

    /**
     * @var array<string, callable(): AdvertisingProvider>
     */
    private array $custom = [];

    /**
     * The adapter for a provider.
     *
     * @throws InvalidArgumentException when the provider is not enabled
     */
    public function for(Provider $provider): AdvertisingProvider
    {
        if (! $provider->isEnabled()) {
            throw new InvalidArgumentException(
                "[{$provider->label()}] is not available. It is either unimplemented or behind a disabled feature flag."
            );
        }

        return $this->resolved[$provider->value] ??= $this->build($provider);
    }

    /** Whether a provider can be used at all right now. */
    public function isAvailable(Provider $provider): bool
    {
        return $provider->isEnabled();
    }

    /**
     * Whether a provider can do a particular thing (spec §87).
     *
     * Answers false for an unavailable provider rather than raising, so a
     * caller checking capability before acting does not have to check
     * availability first as well.
     */
    public function supports(Provider $provider, ProviderCapability $capability): bool
    {
        if (! $this->isAvailable($provider)) {
            return false;
        }

        return $this->for($provider)->supports($capability);
    }

    /**
     * Every adapter that can be used right now.
     *
     * @return list<AdvertisingProvider>
     */
    public function available(): array
    {
        return array_map(
            fn (Provider $provider): AdvertisingProvider => $this->for($provider),
            Provider::enabled(),
        );
    }

    /**
     * Register an adapter directly. Used by tests and by a future live driver
     * that needs constructor arguments the container cannot guess.
     *
     * @param  callable(): AdvertisingProvider  $factory
     */
    public function extend(Provider $provider, callable $factory): void
    {
        $this->custom[$provider->value] = $factory;
        unset($this->resolved[$provider->value]);
    }

    private function build(Provider $provider): AdvertisingProvider
    {
        if (isset($this->custom[$provider->value])) {
            return ($this->custom[$provider->value])();
        }

        $driver = (string) config('platform.advertising.driver', 'mock');

        return match ($driver) {
            'mock' => $this->buildMock($provider),
            'live' => $this->buildLive($provider),
            default => throw new RuntimeException(
                "Advertising driver [{$driver}] is not recognised. Use 'mock' or 'live'."
            ),
        };
    }

    private function buildMock(Provider $provider): AdvertisingProvider
    {
        return match ($provider) {
            Provider::Meta => new MockMetaAdvertisingProvider,
            Provider::Google => new MockGoogleAdvertisingProvider,
            default => throw new RuntimeException(
                "No mock adapter exists for {$provider->value}."
            ),
        };
    }

    /**
     * Live adapters.
     *
     * A provider with no live adapter yet does *not* silently fall back to its
     * mock. A mock answering in a live environment would report campaigns as
     * published when nothing had been sent anywhere, and the failure would only
     * surface when a client asked why their ads never ran (spec §95).
     */
    private function buildLive(Provider $provider): AdvertisingProvider
    {
        return match ($provider) {
            Provider::Meta => $this->buildMeta(),
            default => throw new RuntimeException(
                "No live adapter exists for {$provider->value} yet. "
                .'It is enabled in configuration but not implemented.'
            ),
        };
    }

    private function buildMeta(): AdvertisingProvider
    {
        $config = MetaConfig::fromConfig();

        // Fails here, naming the missing variables, rather than later against
        // Meta with an error nobody can interpret.
        $config->assertUsable();

        return new MetaAdvertisingProvider(
            $config,
            new MetaGraphClient($config, new MetaErrorMapper),
        );
    }
}
