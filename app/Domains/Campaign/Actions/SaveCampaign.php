<?php

declare(strict_types=1);

namespace App\Domains\Campaign\Actions;

use App\Domains\Advertising\Enums\Provider;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Campaign\Enums\BudgetType;
use App\Domains\Campaign\Enums\CampaignObjective;
use App\Domains\Campaign\Enums\CampaignStatus;
use App\Domains\Campaign\Exceptions\CampaignException;
use App\Domains\Campaign\Models\Campaign;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Currency;
use App\Support\Values\Money;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits campaign drafts (spec §21).
 *
 * The budget arrives as the decimal string the client typed and is converted
 * to minor units here, on the server. Nothing accepts a minor-unit figure from
 * a request — a browser that could send `budget_amount: 1` would be setting a
 * campaign's budget to one paisa (Rule 8).
 */
final class SaveCampaign
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(
        Organization $organization,
        string $name,
        Provider $provider,
        CampaignObjective $objective,
        BudgetType $budgetType,
        string $budgetAmount,
        User $actor,
        ?string $startsAt = null,
        ?string $endsAt = null,
    ): Campaign {
        if (! $objective->isSupportedBy($provider)) {
            throw CampaignException::objectiveNotSupported();
        }

        $currency = Currency::of($organization->default_currency);
        $budget = Money::of($budgetAmount, $currency);

        $campaign = DB::transaction(function () use (
            $organization, $name, $provider, $objective, $budgetType, $budget, $actor, $startsAt, $endsAt
        ): Campaign {
            $campaign = new Campaign([
                'organization_id' => $organization->getKey(),
                'name' => trim($name),
                'provider' => $provider,
                'objective' => $objective,
                'status' => CampaignStatus::Draft,
                'currency' => $budget->currency->code,
                'budget_type' => $budgetType,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'created_by' => $actor->getKey(),
            ]);

            // Not fillable: the only place a budget is written is here, from a
            // Money the server built.
            $campaign->budget_amount = $budget->minorUnits;
            $campaign->tenant_id = $organization->tenant_id;
            $campaign->save();

            return $campaign;
        });

        $this->audit->record(
            action: AuditAction::CampaignCreated,
            resource: $campaign,
            after: $campaign->describe(),
            organization: $organization,
            actor: $actor,
        );

        return $campaign;
    }

    /**
     * Update a draft.
     *
     * Absence is expressed by leaving a key out rather than passing null, so
     * clearing an end date and not touching it are distinguishable.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(Campaign $campaign, array $changes, User $actor): Campaign
    {
        if (! $campaign->isEditable()) {
            throw CampaignException::notEditable();
        }

        $before = AuditRecorder::snapshot($campaign);

        $permitted = array_intersect_key($changes, array_flip([
            'name',
            'objective',
            'budget_type',
            'starts_at',
            'ends_at',
        ]));

        $objective = $permitted['objective'] ?? null;

        if ($objective instanceof CampaignObjective && ! $objective->isSupportedBy($campaign->provider)) {
            throw CampaignException::objectiveNotSupported();
        }

        DB::transaction(function () use ($campaign, $permitted, $changes): void {
            $campaign->fill($permitted);

            if (array_key_exists('budget_amount', $changes)) {
                $campaign->budget_amount = Money::of(
                    (string) $changes['budget_amount'],
                    $campaign->currency(),
                )->minorUnits;
            }

            $campaign->save();
        });

        $this->audit->recordChange(
            action: AuditAction::CampaignUpdated,
            resource: $campaign,
            before: $before,
            actor: $actor,
        );

        return $campaign;
    }
}
