<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * The advertising platforms the system can talk to (spec §15).
 *
 * Meta and Google are implemented; the rest are declared so the vocabulary is
 * settled before their adapters exist — a provider added later should not force
 * a migration or a rename of stored rows.
 */
enum Provider: string
{
    case Meta = 'META';
    case Google = 'GOOGLE';
    case TikTok = 'TIKTOK';
    case LinkedIn = 'LINKEDIN';
    case Snapchat = 'SNAPCHAT';

    public function label(): string
    {
        return match ($this) {
            self::Meta => 'Meta',
            self::Google => 'Google Ads',
            self::TikTok => 'TikTok Ads',
            self::LinkedIn => 'LinkedIn Ads',
            self::Snapchat => 'Snapchat Ads',
        };
    }

    /** What a client would recognise the connection as. */
    public function connectionLabel(): string
    {
        return match ($this) {
            self::Meta => 'Facebook and Instagram',
            self::Google => 'Google Ads',
            self::TikTok => 'TikTok',
            self::LinkedIn => 'LinkedIn',
            self::Snapchat => 'Snapchat',
        };
    }

    /** Whether an adapter exists for this provider today. */
    public function isImplemented(): bool
    {
        return in_array($this, [self::Meta, self::Google], true);
    }

    /**
     * The feature flag gating this provider, if any. Google Ads is behind one
     * until its adapter is production-ready (spec §84).
     */
    public function featureFlag(): ?string
    {
        return match ($this) {
            self::Google => 'google_ads',
            default => null,
        };
    }

    public function isEnabled(): bool
    {
        if (! $this->isImplemented()) {
            return false;
        }

        $flag = $this->featureFlag();

        return $flag === null || (bool) config("platform.features.{$flag}");
    }

    /**
     * The asset types this provider exposes (spec §15).
     *
     * @return list<AssetType>
     */
    public function assetTypes(): array
    {
        return match ($this) {
            self::Meta => [
                AssetType::FacebookPage,
                AssetType::InstagramAccount,
                AssetType::MetaBusiness,
                AssetType::MetaPixel,
                AssetType::MetaAdAccount,
            ],
            self::Google => [
                AssetType::GoogleAdsAccount,
                AssetType::GoogleAnalyticsProperty,
                AssetType::GoogleMerchantCenter,
            ],
            default => [],
        };
    }

    /**
     * @return list<self>
     */
    public static function enabled(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $provider->isEnabled(),
        ));
    }
}
