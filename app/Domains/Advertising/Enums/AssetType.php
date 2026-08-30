<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * Kinds of advertising asset a client can connect (spec §15).
 */
enum AssetType: string
{
    case FacebookPage = 'FACEBOOK_PAGE';
    case InstagramAccount = 'INSTAGRAM_ACCOUNT';
    case MetaBusiness = 'META_BUSINESS';
    case MetaPixel = 'META_PIXEL';
    case MetaAdAccount = 'META_AD_ACCOUNT';
    case GoogleAdsAccount = 'GOOGLE_ADS_ACCOUNT';
    case GoogleAnalyticsProperty = 'GOOGLE_ANALYTICS_PROPERTY';
    case GoogleMerchantCenter = 'GOOGLE_MERCHANT_CENTER';

    public function label(): string
    {
        return match ($this) {
            self::FacebookPage => 'Facebook page',
            self::InstagramAccount => 'Instagram account',
            self::MetaBusiness => 'Meta business',
            self::MetaPixel => 'Meta pixel',
            self::MetaAdAccount => 'Meta ad account',
            self::GoogleAdsAccount => 'Google Ads account',
            self::GoogleAnalyticsProperty => 'Google Analytics property',
            self::GoogleMerchantCenter => 'Google Merchant Center',
        };
    }

    public function provider(): Provider
    {
        return match ($this) {
            self::FacebookPage, self::InstagramAccount, self::MetaBusiness,
            self::MetaPixel, self::MetaAdAccount => Provider::Meta,
            self::GoogleAdsAccount, self::GoogleAnalyticsProperty,
            self::GoogleMerchantCenter => Provider::Google,
        };
    }

    /**
     * Whether an ad can be published in this asset's name. A pixel measures and
     * a business owns; only a page or profile can be the identity on an ad
     * (spec §21 Step 2).
     */
    public function canBeAdIdentity(): bool
    {
        return in_array($this, [self::FacebookPage, self::InstagramAccount], true);
    }

    /** Whether this asset carries conversion data rather than being an identity. */
    public function isMeasurement(): bool
    {
        return in_array($this, [
            self::MetaPixel,
            self::GoogleAnalyticsProperty,
        ], true);
    }
}
