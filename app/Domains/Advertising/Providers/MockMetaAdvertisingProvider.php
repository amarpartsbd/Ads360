<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Providers;

use App\Domains\Advertising\DTOs\DiscoveredAsset;
use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\ProviderCapability;

/**
 * Meta stand-in for development and tests (spec §95).
 *
 * Shaped like the real thing: a page, an Instagram profile linked to it, a
 * business, a pixel and an ad account — which is what asset discovery has to
 * cope with against a genuine Meta account.
 */
final class MockMetaAdvertisingProvider extends MockAdvertisingProvider
{
    public function provider(): Provider
    {
        return Provider::Meta;
    }

    public function supports(ProviderCapability $capability): bool
    {
        // Meta does send webhooks, unlike the base mock.
        return true;
    }

    /**
     * @return list<DiscoveredAsset>
     */
    protected function defaultAssets(): array
    {
        return [
            new DiscoveredAsset(
                type: AssetType::FacebookPage,
                externalId: '100000000000001',
                name: 'Demo Retail Bangladesh',
                status: 'PUBLISHED',
                metadata: ['category' => 'Retail company', 'followers' => 12500],
            ),
            new DiscoveredAsset(
                type: AssetType::InstagramAccount,
                externalId: '178000000000002',
                name: '@demoretailbd',
                status: 'ACTIVE',
                // Instagram profiles reach the platform through the page they
                // are linked to, which the campaign builder needs to know.
                metadata: ['linked_page_id' => '100000000000001', 'followers' => 8400],
            ),
            new DiscoveredAsset(
                type: AssetType::MetaBusiness,
                externalId: '200000000000003',
                name: 'Demo Retail Business Manager',
                status: 'VERIFIED',
                metadata: ['verification_status' => 'verified'],
            ),
            new DiscoveredAsset(
                type: AssetType::MetaPixel,
                externalId: '300000000000004',
                name: 'Demo Retail Pixel',
                status: 'ACTIVE',
                metadata: ['last_fired_at' => null],
            ),
            new DiscoveredAsset(
                type: AssetType::MetaAdAccount,
                externalId: 'act_400000000000005',
                name: 'Demo Retail Ads',
                currency: 'USD',
                timezone: 'Asia/Dhaka',
                status: 'ACTIVE',
                metadata: ['business_id' => '200000000000003'],
            ),
        ];
    }
}
