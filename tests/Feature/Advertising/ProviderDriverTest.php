<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Providers\Google\GoogleAdsProvider;
use App\Domains\Advertising\Providers\Meta\MetaAdvertisingProvider;
use App\Domains\Advertising\Providers\MockAdvertisingProvider;
use App\Domains\Advertising\Providers\MockMetaAdvertisingProvider;
use App\Domains\Advertising\Services\ProviderManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Which adapter answers, and when (spec §87, §95).
 *
 * The dangerous mistake this guards against is a mock answering where a live
 * adapter was expected: campaigns would report as published while nothing had
 * been sent anywhere, and nobody would find out until a client asked why their
 * ads never ran.
 */
final class ProviderDriverTest extends TestCase
{
    #[Test]
    public function the_mock_driver_is_the_default(): void
    {
        $this->assertSame('mock', config('platform.advertising.driver'));

        $this->assertInstanceOf(
            MockMetaAdvertisingProvider::class,
            app(ProviderManager::class)->for(Provider::Meta),
        );
    }

    #[Test]
    public function the_live_driver_resolves_the_meta_adapter_when_configured(): void
    {
        $this->withLiveCredentials();

        $this->assertInstanceOf(
            MetaAdvertisingProvider::class,
            app(ProviderManager::class)->for(Provider::Meta),
        );
    }

    #[Test]
    public function the_live_driver_resolves_the_google_adapter_when_configured(): void
    {
        $this->withLiveCredentials();

        $this->assertInstanceOf(
            GoogleAdsProvider::class,
            app(ProviderManager::class)->for(Provider::Google),
        );
    }

    #[Test]
    public function the_live_driver_names_what_is_missing_rather_than_failing_obscurely(): void
    {
        config()->set('platform.advertising.driver', 'live');
        config()->set('services.meta.app_id', null);
        config()->set('services.meta.app_secret', null);
        config()->set('services.meta.redirect_uri', null);

        try {
            app(ProviderManager::class)->for(Provider::Meta);
            $this->fail('Building the live adapter should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('META_APP_ID', $exception->getMessage());
            $this->assertStringContainsString('META_APP_SECRET', $exception->getMessage());
        }
    }

    #[Test]
    public function the_google_adapter_names_the_developer_token_it_is_missing(): void
    {
        config()->set('platform.advertising.driver', 'live');
        config()->set('platform.features.google_ads', true);
        config()->set('services.google_ads.client_id', '1234.apps.googleusercontent.com');
        config()->set('services.google_ads.client_secret', 'test-secret');
        config()->set('services.google_ads.developer_token', null);
        config()->set('services.google_ads.redirect_uri', 'https://ads360.test/callback');

        try {
            app(ProviderManager::class)->for(Provider::Google);
            $this->fail('Building the live adapter should have failed.');
        } catch (RuntimeException $exception) {
            // The credential people forget, because it is not an OAuth one.
            $this->assertStringContainsString('GOOGLE_ADS_DEVELOPER_TOKEN', $exception->getMessage());
        }
    }

    #[Test]
    public function no_enabled_provider_falls_back_to_a_mock_under_the_live_driver(): void
    {
        $this->withLiveCredentials();

        $enabled = Provider::enabled();

        $this->assertNotSame([], $enabled);

        foreach ($enabled as $provider) {
            // A mock answering in a live environment would report campaigns as
            // published when nothing had been sent anywhere (spec §95).
            $this->assertNotInstanceOf(
                MockAdvertisingProvider::class,
                app(ProviderManager::class)->for($provider),
                "[{$provider->value}] resolved to a mock under the live driver.",
            );
        }
    }

    #[Test]
    public function a_provider_with_no_adapter_is_refused_outright(): void
    {
        $this->withLiveCredentials();

        $this->assertFalse(Provider::TikTok->isImplemented());

        $this->expectException(InvalidArgumentException::class);

        app(ProviderManager::class)->for(Provider::TikTok);
    }

    #[Test]
    public function an_unrecognised_driver_is_refused(): void
    {
        config()->set('platform.advertising.driver', 'somethingelse');

        $this->expectException(RuntimeException::class);

        app(ProviderManager::class)->for(Provider::Meta);
    }

    #[Test]
    public function the_meta_adapter_declares_only_what_it_actually_implements(): void
    {
        $this->withLiveCredentials();

        $adapter = app(ProviderManager::class)->for(Provider::Meta);

        $this->assertTrue($adapter->supports(ProviderCapability::CampaignCreation));
        $this->assertTrue($adapter->supports(ProviderCapability::MetricsRetrieval));
        $this->assertTrue($adapter->supports(ProviderCapability::Webhooks));

        // Lead retrieval needs its own permission and its own handling of
        // personal data. Claiming it before that exists would have callers
        // offer clients something that does not work (spec §87).
        $this->assertFalse($adapter->supports(ProviderCapability::LeadForms));
    }

    #[Test]
    public function the_google_adapter_declares_only_what_it_actually_implements(): void
    {
        $this->withLiveCredentials();

        $adapter = app(ProviderManager::class)->for(Provider::Google);

        $this->assertTrue($adapter->supports(ProviderCapability::CampaignCreation));
        $this->assertTrue($adapter->supports(ProviderCapability::MetricsRetrieval));
        $this->assertTrue($adapter->supports(ProviderCapability::TokenRefresh));

        // Google Ads has no push mechanism, exposes an account spend limit
        // only for invoiced accounts this adapter does not read, and has no
        // lead form support here. Callers take the §87 fallback for each.
        $this->assertFalse($adapter->supports(ProviderCapability::Webhooks));
        $this->assertFalse($adapter->supports(ProviderCapability::SpendLimits));
        $this->assertFalse($adapter->supports(ProviderCapability::LeadForms));
    }

    #[Test]
    public function the_mock_google_adapter_reports_the_same_capabilities_as_the_live_one(): void
    {
        $this->withLiveCredentials();

        $live = app(ProviderManager::class)->for(Provider::Google);

        config()->set('platform.advertising.driver', 'mock');
        $manager = new ProviderManager;
        $mock = $manager->for(Provider::Google);

        foreach (ProviderCapability::cases() as $capability) {
            // A mock that claimed more than the live adapter would let a
            // caller skip its fallback in development and meet the limitation
            // for the first time in production (spec §87, §95).
            $this->assertSame(
                $live->supports($capability),
                $mock->supports($capability),
                "The mock and live Google adapters disagree about [{$capability->value}].",
            );
        }
    }

    private function withLiveCredentials(): void
    {
        config()->set('platform.advertising.driver', 'live');
        config()->set('platform.features.google_ads', true);

        config()->set('services.meta.app_id', '1234567890');
        config()->set('services.meta.app_secret', 'test-app-secret');
        config()->set('services.meta.redirect_uri', 'https://ads360.test/callback');

        config()->set('services.google_ads.client_id', '1234.apps.googleusercontent.com');
        config()->set('services.google_ads.client_secret', 'test-client-secret');
        config()->set('services.google_ads.developer_token', 'test-developer-token');
        config()->set('services.google_ads.redirect_uri', 'https://ads360.test/callback/google');
    }
}
