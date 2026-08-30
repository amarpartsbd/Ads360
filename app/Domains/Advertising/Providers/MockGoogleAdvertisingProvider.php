<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers;

use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;

/**
 * Google Ads stand-in for development and tests (spec §95).
 */
final class MockGoogleAdvertisingProvider extends MockAdvertisingProvider
{
    public function provider(): Provider
    {
        return Provider::Google;
    }

    public function supports(ProviderCapability $capability): bool
    {
        // Google Ads has no webhook equivalent, so status changes are found by
        // polling. Callers that check this capability take the polling path,
        // which is exactly the §87 fallback being exercised.
        return $capability !== ProviderCapability::Webhooks;
    }

    /**
     * @return list<DiscoveredAsset>
     */
    protected function defaultAssets(): array
    {
        return [
            new DiscoveredAsset(
                type: AssetType::GoogleAdsAccount,
                externalId: '123-456-7890',
                name: 'Demo Retail — Google Ads',
                currency: 'USD',
                timezone: 'Asia/Dhaka',
                status: 'ENABLED',
                metadata: ['manager_account' => false],
            ),
            new DiscoveredAsset(
                type: AssetType::GoogleAnalyticsProperty,
                externalId: 'properties/987654321',
                name: 'Demo Retail — GA4',
                timezone: 'Asia/Dhaka',
                status: 'ACTIVE',
                metadata: ['measurement_id' => 'G-DEMO12345'],
            ),
        ];
    }
}
