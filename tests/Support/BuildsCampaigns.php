<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Models\AdAccount;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Campaign\Actions\ApproveCampaign;
use App\Domains\Campaign\Actions\SaveAd;
use App\Domains\Campaign\Actions\SaveAdSet;
use App\Domains\Campaign\Actions\SaveCampaign;
use App\Domains\Campaign\Actions\SubmitCampaign;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Campaign\Values\Targeting;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Services\WalletService;
use App\Support\Values\Money;
use Database\Seeders\FinanceSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * Builds campaigns through the real actions rather than fabricating rows.
 *
 * Deliberate: an approved campaign in this system holds money and an ad
 * account, and the schema refuses one that does not. A helper that inserted
 * the row directly would let a test assert behaviour the database would never
 * permit — so the fixture walks the same path production does.
 */
trait BuildsCampaigns
{
    protected ?Organization $campaignOrganization = null;

    protected ?User $campaignClient = null;

    protected ?User $campaignReviewer = null;

    protected ?AdAccount $campaignAdAccount = null;

    /**
     * A campaign that is complete, submitted, approved, funded and allocated —
     * everything short of published.
     */
    protected function approvedCampaign(string $budget = '5000.00'): Campaign
    {
        $campaign = $this->submittedCampaign($budget);

        return app(ApproveCampaign::class)->handle($campaign->fresh(), $this->reviewer());
    }

    /** A complete campaign sitting in the review queue. */
    protected function submittedCampaign(string $budget = '5000.00'): Campaign
    {
        $campaign = $this->draftCampaign($budget);

        return app(SubmitCampaign::class)->handle($campaign->fresh(), $this->client());
    }

    /** A complete draft: one audience, one ad, everything readiness asks for. */
    protected function draftCampaign(string $budget = '5000.00'): Campaign
    {
        $this->prepareCampaignWorkspace();

        $organization = $this->campaignOrganization();

        $campaign = app(SaveCampaign::class)->create(
            organization: $organization,
            name: 'Test Campaign',
            provider: Provider::Meta,
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Lifetime,
            budgetAmount: $budget,
            actor: $this->client(),
            startsAt: now()->addDay()->toDateTimeString(),
            endsAt: now()->addDays(11)->toDateTimeString(),
        );

        $adSet = app(SaveAdSet::class)->create(
            campaign: $campaign,
            name: 'Bangladesh adults',
            targeting: Targeting::fromArray(['countries' => ['BD']]),
            bidStrategy: BidStrategy::LowestCost,
        );

        app(SaveAd::class)->create(
            adSet: $adSet,
            name: 'Primary ad',
            headline: 'A headline',
            primaryText: 'Some body copy for the ad.',
            destinationUrl: 'https://example.test/landing',
            creative: $this->campaignCreative(),
            identity: $this->campaignIdentity(),
        );

        return $campaign->fresh();
    }

    protected function campaignOrganization(): Organization
    {
        return $this->campaignOrganization ??= Organization::factory()->create([
            'default_currency' => 'BDT',
            'country' => 'BD',
        ]);
    }

    protected function client(): User
    {
        return $this->campaignClient ??= User::factory()
            ->memberOf($this->campaignOrganization())
            ->create();
    }

    protected function reviewer(): User
    {
        return $this->campaignReviewer ??= User::factory()->platform()->create();
    }

    protected function campaignWallet(): Wallet
    {
        return app(WalletService::class)->walletFor($this->campaignOrganization(), 'BDT');
    }

    protected function allocatedAdAccount(): AdAccount
    {
        return $this->campaignAdAccount ??= AdAccount::factory()->create([
            'daily_spend_limit' => 1_000_000_000,
            'monthly_spend_limit' => 1_000_000_000,
        ]);
    }

    /**
     * Pricing plans, a funded wallet, a verified profile, a live pool with a
     * roomy account, and a connected page to advertise as.
     */
    protected function prepareCampaignWorkspace(string $funding = '500000.00'): void
    {
        if ($this->campaignPrepared) {
            return;
        }

        $this->campaignPrepared = true;

        Storage::fake('creatives');

        // Pricing is configuration the platform needs, not a fixture.
        app(FinanceSeeder::class)->run();

        $organization = $this->campaignOrganization();

        $this->verifiedProfileFor($organization);

        $wallet = $this->campaignWallet();
        app(WalletService::class)->deposit($wallet, Money::of($funding, 'BDT'), 'Test funding');

        $pool = AdAccountPool::factory()->status(PoolStatus::Active)->create([
            'provider' => Provider::Meta,
            'currency' => 'BDT',
        ]);

        $pool->accounts()->attach($this->allocatedAdAccount()->getKey(), ['weight' => 1]);
    }

    /**
     * Campaigns cannot be submitted by an unverified client, so the fixture
     * gives the organization an approved profile through the factory's own
     * state — which satisfies the completeness constraint the table enforces.
     */
    protected function verifiedProfileFor(Organization $organization): void
    {
        \App\Domains\Compliance\Models\VerificationProfile::factory()
            ->forOrganization($organization)
            ->verified()
            ->create();
    }

    protected function campaignCreative(): Creative
    {
        return $this->campaignCreativeInstance ??= Creative::factory()
            ->forOrganization($this->campaignOrganization())
            ->withStoredBytes()
            ->create();
    }

    protected function campaignIdentity(): ProviderAsset
    {
        if ($this->campaignIdentityInstance !== null) {
            return $this->campaignIdentityInstance;
        }

        $connection = ProviderConnection::factory()
            ->forOrganization($this->campaignOrganization())
            ->create();

        return $this->campaignIdentityInstance = ProviderAsset::factory()
            ->forConnection($connection)
            ->ofType(AssetType::FacebookPage)
            ->create();
    }

    private bool $campaignPrepared = false;

    private ?Creative $campaignCreativeInstance = null;

    private ?ProviderAsset $campaignIdentityInstance = null;
}
