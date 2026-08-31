<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
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
use App\Domains\Compliance\Enums\VerificationStatus;
use App\Domains\Compliance\Models\VerificationProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Integration\Enums\AssetStatus;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Integration\Models\ProviderConnection;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * A couple of campaigns for development, so the builder and the review queue
 * are not empty on a fresh install.
 *
 * Built through the real actions rather than inserted, so the seeded data is
 * data the application could actually have produced. Development only.
 */
class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // A funded wallet is the one thing this fixture cannot conjure — a
        // campaign the client cannot pay for will not submit.
        $fundedOrganizationId = Wallet::query()
            ->withoutGlobalScopes()
            ->where('available_balance_cached', '>', 0)
            ->value('organization_id');

        $organization = $fundedOrganizationId === null
            ? null
            : Organization::query()->withoutGlobalScopes()->find($fundedOrganizationId);

        if ($organization === null) {
            return;
        }

        $actor = User::query()
            ->where('tenant_id', $organization->tenant_id)
            ->first();

        if ($actor === null) {
            return;
        }

        // A campaign needs a verified client and a page to advertise as. The
        // demo workspace deliberately leaves clients in mixed compliance
        // states, so the pieces this fixture needs are added here rather than
        // by reaching back into the compliance seeder.
        $this->ensureVerified($organization);

        $identity = $this->identityFor($organization);
        $creative = $this->creativeFor($organization);

        try {
            $this->buildCampaign($organization, $actor, $creative, $identity, submit: true);
            $this->buildCampaign($organization, $actor, $creative, $identity, submit: false);
        } catch (Throwable $exception) {
            // Seeding is a convenience. A workspace missing a connected page or
            // a funded wallet simply gets fewer fixtures, not a failed install.
            //
            // Logged rather than printed: seeding runs from CI and container
            // builds as often as from a terminal, and a warning that only
            // appears when a console command happens to be attached is the one
            // nobody sees when it matters.
            Log::warning('Campaign fixtures skipped: '.$exception->getMessage());
        }
    }

    /**
     * Gives the fixture organization an approved profile if it has none, so
     * the campaign screens are usable on a fresh install.
     */
    private function ensureVerified(Organization $organization): void
    {
        $profile = VerificationProfile::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->first();

        if ($profile === null) {
            VerificationProfile::factory()->forOrganization($organization)->verified()->create();

            return;
        }

        if ($profile->status !== VerificationStatus::Verified) {
            $profile->forceFill([
                'status' => VerificationStatus::Verified,
                'submitted_at' => $profile->submitted_at ?? now()->subDays(3),
                'reviewed_at' => now()->subDay(),
            ])->save();
        }
    }

    /** An authorised page for ads to run under, connected if there is none. */
    private function identityFor(Organization $organization): ProviderAsset
    {
        $existing = ProviderAsset::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('status', AssetStatus::Available)
            ->get()
            ->first(static fn (ProviderAsset $asset): bool => $asset->canBeAdIdentity());

        if ($existing !== null) {
            return $existing;
        }

        $connection = ProviderConnection::factory()->forOrganization($organization)->create();

        return ProviderAsset::factory()
            ->forConnection($connection)
            ->ofType(AssetType::FacebookPage)
            ->create(['name' => $organization->name.' Page']);
    }

    private function buildCampaign(
        Organization $organization,
        User $actor,
        ?Creative $creative,
        ?ProviderAsset $identity,
        bool $submit,
    ): void {
        $campaign = app(SaveCampaign::class)->create(
            organization: $organization,
            name: $submit ? 'Eid Collection Launch' : 'Winter Range (draft)',
            provider: Provider::Meta,
            objective: CampaignObjective::Traffic,
            budgetType: BudgetType::Lifetime,
            budgetAmount: $submit ? '25000.00' : '8000.00',
            actor: $actor,
            startsAt: now()->addDays(2)->toDateTimeString(),
            endsAt: now()->addDays(16)->toDateTimeString(),
        );

        $adSet = app(SaveAdSet::class)->create(
            campaign: $campaign,
            name: 'Dhaka and Chattogram, 25–45',
            targeting: Targeting::fromArray([
                'countries' => ['BD'],
                'cities' => ['Dhaka', 'Chattogram'],
                'minimum_age' => 25,
                'maximum_age' => 45,
            ]),
            bidStrategy: BidStrategy::LowestCost,
        );

        app(SaveAd::class)->create(
            adSet: $adSet,
            name: 'Primary ad',
            headline: 'New season, new collection',
            primaryText: 'Free delivery across Bangladesh on orders over 2,000 taka.',
            destinationUrl: 'https://example.test/collection',
            creative: $creative,
            identity: $identity,
            callToAction: 'SHOP_NOW',
        );

        if ($submit) {
            app(SubmitCampaign::class)->handle($campaign->fresh(), $actor);
        }
    }

    /**
     * A placeholder image, written to the private disk so the download route
     * has something real to serve.
     */
    private function creativeFor(Organization $organization): ?Creative
    {
        $existing = Creative::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        );

        if ($bytes === false) {
            return null;
        }

        $path = sprintf('%s/image/%s.png', $organization->public_id, Str::ulid());

        Storage::disk('creatives')->put($path, $bytes);

        $creative = new Creative([
            'organization_id' => $organization->getKey(),
            'name' => 'placeholder.png',
            'type' => 'IMAGE',
            'storage_path' => $path,
            'media_type' => 'image/png',
            'byte_size' => strlen($bytes),
            'width' => 1200,
            'height' => 1200,
            'checksum' => hash('sha256', $bytes),
            'status' => 'READY',
        ]);

        $creative->tenant_id = $organization->tenant_id;
        $creative->save();

        return $creative;
    }
}
