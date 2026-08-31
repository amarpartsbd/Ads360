<?php

declare(strict_types=1);

namespace Tests\Feature\Advertising\Meta;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;
use App\Domains\Advertising\Providers\Meta\MetaAdvertisingProvider;
use App\Domains\Advertising\Providers\MockMetaAdvertisingProvider;
use App\Domains\Advertising\Services\ProviderManager;
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
        $this->withMetaCredentials();

        $this->assertInstanceOf(
            MetaAdvertisingProvider::class,
            app(ProviderManager::class)->for(Provider::Meta),
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
    public function a_provider_with_no_live_adapter_is_refused_rather_than_falling_back_to_its_mock(): void
    {
        $this->withMetaCredentials();
        config()->set('platform.features.google_ads', true);

        try {
            app(ProviderManager::class)->for(Provider::Google);
            $this->fail('Google should have no live adapter yet.');
        } catch (RuntimeException $exception) {
            // A mock answering in a live environment would report campaigns
            // as published when nothing had been sent.
            $this->assertStringContainsString('No live adapter', $exception->getMessage());
        }
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
        $this->withMetaCredentials();

        $adapter = app(ProviderManager::class)->for(Provider::Meta);

        $this->assertTrue($adapter->supports(ProviderCapability::CampaignCreation));
        $this->assertTrue($adapter->supports(ProviderCapability::MetricsRetrieval));
        $this->assertTrue($adapter->supports(ProviderCapability::Webhooks));

        // Lead retrieval needs its own permission and its own handling of
        // personal data. Claiming it before that exists would have callers
        // offer clients something that does not work (spec §87).
        $this->assertFalse($adapter->supports(ProviderCapability::LeadForms));
    }

    private function withMetaCredentials(): void
    {
        config()->set('platform.advertising.driver', 'live');
        config()->set('services.meta.app_id', '1234567890');
        config()->set('services.meta.app_secret', 'test-app-secret');
        config()->set('services.meta.redirect_uri', 'https://ads360.test/callback');
    }
}
