<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Enums;

use App\Domains\Advertising\Enums\Provider;

/**
 * What a campaign is trying to achieve (spec §21).
 *
 * One vocabulary across providers. Each adapter maps these to whatever its
 * platform calls them, so a caller never writes `OUTCOME_TRAFFIC` or
 * `MAXIMIZE_CONVERSIONS` — those are provider dialects and belong in adapters.
 */
enum CampaignObjective: string
{
    case Awareness = 'AWARENESS';
    case Traffic = 'TRAFFIC';
    case Engagement = 'ENGAGEMENT';
    case Leads = 'LEADS';
    case AppPromotion = 'APP_PROMOTION';
    case Sales = 'SALES';

    public function label(): string
    {
        return match ($this) {
            self::Awareness => 'Brand awareness',
            self::Traffic => 'Website traffic',
            self::Engagement => 'Engagement',
            self::Leads => 'Lead generation',
            self::AppPromotion => 'App promotion',
            self::Sales => 'Sales and conversions',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Awareness => 'Reach as many relevant people as possible.',
            self::Traffic => 'Send people to a page on your website.',
            self::Engagement => 'Get more interaction with your posts and profile.',
            self::Leads => 'Collect enquiries from people interested in your business.',
            self::AppPromotion => 'Get more installs and activity in your app.',
            self::Sales => 'Drive purchases, measured by your conversion tracking.',
        };
    }

    /**
     * Whether the objective needs somewhere to send people. Awareness and
     * engagement campaigns run without one; the rest are pointless without.
     */
    public function requiresDestination(): bool
    {
        return $this !== self::Awareness && $this !== self::Engagement;
    }

    /**
     * Whether conversion tracking has to be in place for the objective to
     * mean anything (spec §26).
     */
    public function requiresConversionTracking(): bool
    {
        return in_array($this, [self::Sales, self::Leads], true);
    }

    /**
     * Objectives a provider supports. Declared here rather than assumed,
     * because offering a client an objective the adapter cannot publish is a
     * promise broken at the last step (spec §87).
     *
     * @return list<self>
     */
    public static function for(Provider $provider): array
    {
        return match ($provider) {
            Provider::Meta => self::cases(),
            /*
             * What the live Google adapter publishes, which is search
             * campaigns. Each of these three is a bidding strategy a search
             * campaign genuinely serves: maximise clicks, maximise
             * conversions, maximise conversion value.
             *
             * Awareness and app promotion are absent because they are not
             * search campaigns at Google — they are display and app campaigns,
             * with different ad formats, different creative requirements and
             * different targeting. Offering them here before those exist would
             * let a client build a campaign the adapter cannot publish, and
             * they would find out at approval rather than while choosing
             * (spec §87). They come back when those campaign types do.
             */
            Provider::Google => [
                self::Traffic,
                self::Leads,
                self::Sales,
            ],
            default => [self::Awareness, self::Traffic],
        };
    }

    public function isSupportedBy(Provider $provider): bool
    {
        return in_array($this, self::for($provider), true);
    }
}
