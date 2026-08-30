<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Enums;

/**
 * Things a provider adapter may or may not be able to do (spec §87).
 *
 * The specification is explicit that the platform must not assume a provider
 * will always permit a given account-sharing, billing or managed-account
 * workflow. Capability is therefore asked, never assumed: a caller checks
 * `supports()` and degrades gracefully rather than discovering the limitation
 * as a failed API call halfway through publishing a campaign.
 */
enum ProviderCapability: string
{
    case AssetDiscovery = 'ASSET_DISCOVERY';
    case CampaignCreation = 'CAMPAIGN_CREATION';
    case CampaignPause = 'CAMPAIGN_PAUSE';
    case MetricsRetrieval = 'METRICS_RETRIEVAL';
    case Webhooks = 'WEBHOOKS';
    case TokenRefresh = 'TOKEN_REFRESH';

    /** Managed ad accounts the platform owns and lends to clients (spec §17). */
    case ManagedAdAccounts = 'MANAGED_AD_ACCOUNTS';

    /** Reading a client's own spend limits and billing state (spec §20). */
    case SpendLimits = 'SPEND_LIMITS';

    case LeadForms = 'LEAD_FORMS';

    public function label(): string
    {
        return match ($this) {
            self::AssetDiscovery => 'Discover authorised assets',
            self::CampaignCreation => 'Create campaigns',
            self::CampaignPause => 'Pause and resume campaigns',
            self::MetricsRetrieval => 'Retrieve performance metrics',
            self::Webhooks => 'Receive webhooks',
            self::TokenRefresh => 'Refresh access tokens',
            self::ManagedAdAccounts => 'Use managed ad accounts',
            self::SpendLimits => 'Read spend limits and billing state',
            self::LeadForms => 'Use lead forms',
        };
    }

    /**
     * What to tell a client when this is unavailable. Written for them, not for
     * a log (spec §80).
     */
    public function unavailableMessage(): string
    {
        return match ($this) {
            self::AssetDiscovery => 'We cannot list your assets from this provider yet.',
            self::CampaignCreation => 'Campaigns cannot be published to this provider yet.',
            self::CampaignPause => 'Campaigns on this provider cannot be paused from here.',
            self::MetricsRetrieval => 'Performance data is not available from this provider yet.',
            self::Webhooks => 'This provider does not send us live updates.',
            self::TokenRefresh => 'This connection will need to be renewed by hand when it expires.',
            self::ManagedAdAccounts => 'This provider does not allow campaigns through our managed accounts.',
            self::SpendLimits => 'Spend limits are not reported by this provider.',
            self::LeadForms => 'Lead forms are not available on this provider.',
        };
    }
}
