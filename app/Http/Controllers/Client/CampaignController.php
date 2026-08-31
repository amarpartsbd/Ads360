<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Domains\Advertising\Enums\AssetType;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Exceptions\ProviderUnavailable;
use App\Domains\Advertising\Services\ProviderManager;
use App\Domains\Campaign\Actions\ControlCampaign;
use App\Domains\Campaign\Actions\SaveAd;
use App\Domains\Campaign\Actions\SaveAdSet;
use App\Domains\Campaign\Actions\SaveCampaign;
use App\Domains\Campaign\Actions\SubmitCampaign;
use App\Domains\Campaign\Enums\BidStrategy;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Exceptions\IncompleteCampaign;
use App\Domains\Campaign\Models\Ad;
use App\Domains\Campaign\Models\AdSet;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Campaign\Models\Creative;
use App\Domains\Campaign\Services\CampaignCosting;
use App\Domains\Campaign\Services\CampaignReadiness;
use App\Domains\Campaign\Values\Targeting;
use App\Domains\Integration\Models\ProviderAsset;
use App\Domains\Tenant\Services\TenantContext;
use App\Http\Requests\Client\StoreAdRequest;
use App\Http\Requests\Client\StoreAdSetRequest;
use App\Http\Requests\Client\StoreCampaignRequest;
use App\Http\Requests\Client\UpdateCampaignRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The campaign builder, from the client's side (spec §21).
 *
 * Every money figure in these props is formatted by the server. The builder
 * shows a client what a campaign will cost; it never works the number out
 * itself (Rule 8).
 */
final class CampaignController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CampaignCosting $costing,
        private readonly CampaignReadiness $readiness,
    ) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Campaign::class);

        $organization = $this->context->requireOrganization();

        $campaigns = Campaign::query()
            ->where('organization_id', $organization->getKey())
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('Client/Campaigns/Index', [
            'campaigns' => $campaigns->through(fn (Campaign $campaign): array => $this->summarise($campaign)),
            'options' => $this->options(),
            'can' => ['create' => Gate::allows('create', Campaign::class)],
        ]);
    }

    public function show(Campaign $campaign): Response
    {
        Gate::authorize('view', $campaign);

        $campaign->loadMissing(['adSets.ads.creative', 'adSets.ads.identity']);

        return Inertia::render('Client/Campaigns/Show', [
            'campaign' => [
                ...$this->summarise($campaign),
                'objective' => $campaign->objective->value,
                'objectiveLabel' => $campaign->objective->label(),
                'reviewNotes' => $campaign->review_notes,
                'lastError' => $campaign->last_error,
                'startsAt' => $campaign->starts_at?->toIso8601String(),
                'endsAt' => $campaign->ends_at?->toIso8601String(),
                // The breakdown the client agreed to, not today's prices.
                'costs' => $this->costing->storedBreakdown($campaign),
                'adSets' => $campaign->adSets
                    ->map(fn (AdSet $adSet): array => $this->summariseAdSet($adSet))
                    ->values()
                    ->all(),
            ],
            'readiness' => $campaign->isEditable()
                ? $this->readiness->reasonsNotReady($campaign)
                : [],
            'library' => $this->library(),
            'options' => $this->options(),
            'can' => [
                'update' => Gate::allows('update', $campaign),
                'submit' => Gate::allows('submit', $campaign),
                'pause' => Gate::allows('pause', $campaign),
            ],
        ]);
    }

    public function store(StoreCampaignRequest $request, SaveCampaign $save): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $campaign = $save->create(
                organization: $this->context->requireOrganization(),
                name: $validated['name'],
                provider: Provider::from($validated['provider']),
                objective: CampaignObjective::from($validated['objective']),
                budgetType: BudgetType::from($validated['budget_type']),
                budgetAmount: (string) $validated['budget_amount'],
                actor: $request->user(),
                startsAt: $validated['starts_at'],
                endsAt: $validated['ends_at'] ?? null,
            );
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('client.campaigns.show', $campaign)
            ->with('success', 'Campaign created. Add an audience and at least one ad.');
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign, SaveCampaign $save): RedirectResponse
    {
        $validated = $request->validated();
        $changes = [];

        foreach (['name', 'starts_at', 'ends_at', 'budget_amount'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $validated[$field];
            }
        }

        if (isset($validated['objective'])) {
            $changes['objective'] = CampaignObjective::from($validated['objective']);
        }

        if (isset($validated['budget_type'])) {
            $changes['budget_type'] = BudgetType::from($validated['budget_type']);
        }

        try {
            $save->update($campaign, $changes, $request->user());
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Campaign updated.');
    }

    public function submit(Request $request, Campaign $campaign, SubmitCampaign $submit): RedirectResponse
    {
        Gate::authorize('submit', $campaign);

        try {
            $submit->handle($campaign, $request->user());
        } catch (IncompleteCampaign $exception) {
            // Every reason at once, on the field the builder shows them under.
            throw ValidationException::withMessages($exception->toErrorBag());
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Submitted for review. We will come back to you shortly.');
    }

    public function storeAdSet(StoreAdSetRequest $request, Campaign $campaign, SaveAdSet $save): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $save->create(
                campaign: $campaign,
                name: $validated['name'],
                targeting: Targeting::fromArray($validated['targeting']),
                bidStrategy: BidStrategy::from($validated['bid_strategy']),
                bidAmount: isset($validated['bid_amount']) ? (string) $validated['bid_amount'] : null,
                optimizationGoal: $validated['optimization_goal'] ?? null,
            );
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            // The value object is the authority on what targeting may say.
            throw ValidationException::withMessages(['targeting' => $exception->getMessage()]);
        }

        return back()->with('success', 'Audience added.');
    }

    public function destroyAdSet(Campaign $campaign, AdSet $adSet, SaveAdSet $save): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $save->delete($adSet);

        return back()->with('success', 'Audience removed.');
    }

    public function storeAd(StoreAdRequest $request, Campaign $campaign, AdSet $adSet, SaveAd $save): RedirectResponse
    {
        $validated = $request->validated();
        $organization = $this->context->requireOrganization();

        // Resolved against the current organization, so a public identifier
        // from elsewhere finds nothing.
        $creative = isset($validated['creative'])
            ? Creative::query()
                ->where('organization_id', $organization->getKey())
                ->where('public_id', $validated['creative'])
                ->first()
            : null;

        $identity = isset($validated['identity'])
            ? ProviderAsset::query()
                ->where('organization_id', $organization->getKey())
                ->where('public_id', $validated['identity'])
                ->first()
            : null;

        try {
            $save->create(
                adSet: $adSet,
                name: $validated['name'],
                headline: $validated['headline'],
                primaryText: $validated['primary_text'],
                destinationUrl: $validated['destination_url'],
                creative: $creative,
                identity: $identity,
                description: $validated['description'] ?? null,
                callToAction: $validated['call_to_action'] ?? null,
            );
        } catch (CampaignException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Ad added.');
    }

    public function destroyAd(Campaign $campaign, Ad $ad, SaveAd $save): RedirectResponse
    {
        Gate::authorize('update', $campaign);

        $save->delete($ad);

        return back()->with('success', 'Ad removed.');
    }

    public function pause(Request $request, Campaign $campaign, ControlCampaign $control): RedirectResponse
    {
        Gate::authorize('pause', $campaign);

        try {
            $control->pause($campaign, $request->user());
        } catch (ProviderUnavailable|CampaignException $exception) {
            return back()->with('error', $this->messageFor($exception));
        }

        return back()->with('success', 'Campaign paused. No further spend will happen.');
    }

    public function resume(Request $request, Campaign $campaign, ControlCampaign $control): RedirectResponse
    {
        Gate::authorize('resume', $campaign);

        try {
            $control->resume($campaign, $request->user());
        } catch (ProviderUnavailable|CampaignException $exception) {
            return back()->with('error', $this->messageFor($exception));
        }

        return back()->with('success', 'Campaign resumed.');
    }

    private function messageFor(\Throwable $exception): string
    {
        return $exception instanceof ProviderUnavailable
            ? $exception->clientMessage
            : $exception->getMessage();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Campaign $campaign): array
    {
        return [
            ...$campaign->describe(),
            'providerLabel' => $campaign->provider->connectionLabel(),
            'statusLabel' => $campaign->status->label(),
            'statusMessage' => $campaign->status->clientMessage(),
            'editable' => $campaign->isEditable(),
            'live' => $campaign->status->isLive(),
            'budget' => $campaign->budget()->format(),
            'budgetTypeLabel' => $campaign->budget_type->label(),
            'chargedTotal' => $campaign->chargedTotal()->format(),
            'captured' => $campaign->capturedAmount()->format(),
            'remaining' => $campaign->remainingBudget()->format(),
            'reportedSpend' => $campaign->reportedSpend()->format(),
            'submittedAt' => $campaign->submitted_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summariseAdSet(AdSet $adSet): array
    {
        return [
            'id' => $adSet->public_id,
            'name' => $adSet->name,
            'status' => $adSet->status->value,
            'statusLabel' => $adSet->status->label(),
            'statusMessage' => $adSet->status->clientMessage(),
            'targetingSummary' => $this->targetingSummary($adSet),
            'bidStrategy' => $adSet->bid_strategy->label(),
            'ads' => $adSet->ads
                ->map(static fn (Ad $ad): array => [
                    'id' => $ad->public_id,
                    'name' => $ad->name,
                    'headline' => $ad->headline,
                    'status' => $ad->status->value,
                    'statusLabel' => $ad->status->label(),
                    'statusMessage' => $ad->status->clientMessage(),
                    'creative' => $ad->creative?->name,
                    'identity' => $ad->identity?->name,
                    'destinationUrl' => $ad->destination_url,
                    'complete' => $ad->isComplete(),
                ])
                ->values()
                ->all(),
        ];
    }

    /** A stored audience nobody can parse is shown as such, not as "everyone". */
    private function targetingSummary(AdSet $adSet): string
    {
        try {
            return $adSet->targeting()->summary();
        } catch (\InvalidArgumentException) {
            return 'This audience has settings we cannot read. Edit it to fix.';
        }
    }

    /**
     * What the client can choose an ad's image and identity from.
     *
     * @return array<string, mixed>
     */
    private function library(): array
    {
        $organization = $this->context->requireOrganization();

        return [
            'creatives' => Creative::query()
                ->where('organization_id', $organization->getKey())
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (Creative $creative): array => [
                    'id' => $creative->public_id,
                    'name' => $creative->name,
                    'type' => $creative->type->value,
                    'dimensions' => $creative->dimensions(),
                ])
                ->values()
                ->all(),
            'identities' => ProviderAsset::query()
                ->where('organization_id', $organization->getKey())
                ->usable()
                ->get()
                ->filter(static fn (ProviderAsset $asset): bool => $asset->canBeAdIdentity())
                ->map(static fn (ProviderAsset $asset): array => [
                    'id' => $asset->public_id,
                    'name' => $asset->name,
                    'type' => $asset->type->label(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $providers = app(ProviderManager::class)->available();

        return [
            'providers' => array_map(
                static fn ($adapter): array => [
                    'value' => $adapter->provider()->value,
                    'label' => $adapter->provider()->connectionLabel(),
                    'objectives' => array_map(
                        static fn (CampaignObjective $objective): array => [
                            'value' => $objective->value,
                            'label' => $objective->label(),
                            'description' => $objective->description(),
                        ],
                        CampaignObjective::for($adapter->provider()),
                    ),
                ],
                $providers,
            ),
            'budgetTypes' => array_map(
                static fn (BudgetType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                    'requiresEndDate' => $type->requiresEndDate(),
                ],
                BudgetType::cases(),
            ),
            'bidStrategies' => array_map(
                static fn (BidStrategy $strategy): array => [
                    'value' => $strategy->value,
                    'label' => $strategy->label(),
                    'description' => $strategy->description(),
                    'requiresAmount' => $strategy->requiresAmount(),
                ],
                BidStrategy::cases(),
            ),
            'assetTypes' => array_map(
                static fn (AssetType $type): array => ['value' => $type->value, 'label' => $type->label()],
                AssetType::cases(),
            ),
        ];
    }
}
