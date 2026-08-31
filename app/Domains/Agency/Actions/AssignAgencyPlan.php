<?php

declare(strict_types=1);

namespace App\Domains\Agency\Actions;

use App\Domains\Agency\Exceptions\AgencyException;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Billing\Models\PricingPlan;
use App\Domains\Billing\Models\PricingRule;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Puts an agency on its own fee schedule (spec §36, §42).
 *
 * The pricing hierarchy already resolves a tenant-scoped plan ahead of the
 * platform default for every organization of that tenant, so this action does
 * not need to touch resolution at all — it only has to produce the plan. Every
 * client the agency manages, including ones created afterwards, is priced by it
 * from the moment it exists.
 *
 * ## Why the template is copied rather than pointed at
 *
 * A pricing plan is a complete statement of what someone pays, and an invoice
 * from six months ago has to keep explaining itself (§36). If two agencies
 * shared one plan row, changing one agency's terms would silently rewrite the
 * other's, and neither's history would say so. So the schedule is copied, with
 * its rules, into a plan belonging to this agency alone.
 *
 * ## Why the previous plan is deactivated rather than deleted
 *
 * Priced transactions carry a snapshot of the plan that produced them and point
 * at its row. Deleting it would break the explanation of every charge made
 * under it (§62), so it is switched off and left where it is.
 */
final class AssignAgencyPlan
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @throws AgencyException
     */
    public function handle(Tenant $agency, PricingPlan $template, ?User $actor = null): PricingPlan
    {
        if (! $agency->type->managesClients()) {
            throw AgencyException::notAnAgency($agency);
        }

        if ($template->scope !== PricingScope::Platform) {
            /*
             * Only a platform schedule may be assigned. Copying one agency's
             * negotiated plan onto another would move commercial terms between
             * two customers of the platform through a screen that looks like a
             * dropdown.
             */
            throw new AgencyException(
                'Only a platform fee schedule can be assigned to an agency. '
                .'["'.$template->name.'" is a '.$template->scope->label().' plan.]'
            );
        }

        return DB::transaction(function () use ($agency, $template, $actor): PricingPlan {
            $previous = PricingPlan::query()
                ->where('scope', PricingScope::Tenant)
                ->where('tenant_id', $agency->getKey())
                ->where('is_active', true)
                ->get();

            foreach ($previous as $plan) {
                $plan->is_active = false;
                $plan->save();
            }

            /** @var PricingPlan $assigned */
            $assigned = PricingPlan::query()->create([
                'name' => "{$template->name} — {$agency->name}",
                'description' => $template->description,
                'scope' => PricingScope::Tenant,
                'tenant_id' => $agency->getKey(),
                'currency' => $template->currency,
                'is_active' => true,
                // A tenant plan is never the platform default, whatever the
                // template was.
                'is_default' => false,
                'created_by' => $actor?->getKey(),
            ]);

            foreach ($template->rules as $rule) {
                $assigned->rules()->create($this->copyOf($rule));
            }

            $this->audit->record(
                action: AuditAction::AgencyPricingAssigned,
                resource: $agency,
                before: ['previous_plans' => $previous->pluck('name')->all()],
                after: ['plan' => $assigned->name, 'from_template' => $template->name],
                actor: $actor,
            );

            return $assigned->load('rules');
        });
    }

    /**
     * The plan that prices an agency's clients right now, if it has one of its
     * own. Null means it is on the platform default.
     */
    public function current(Tenant $agency): ?PricingPlan
    {
        /** @var PricingPlan|null $plan */
        $plan = PricingPlan::query()
            ->with('rules')
            ->where('scope', PricingScope::Tenant)
            ->where('tenant_id', $agency->getKey())
            ->where('is_active', true)
            ->latest('id')
            ->first();

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function copyOf(PricingRule $rule): array
    {
        return [
            'fee_type' => $rule->fee_type,
            'calculation' => $rule->calculation,
            'percentage' => $rule->percentage,
            'fixed_amount' => $rule->fixed_amount,
            'minimum_amount' => $rule->minimum_amount,
            'maximum_amount' => $rule->maximum_amount,
            'applies_from_amount' => $rule->applies_from_amount,
            'priority' => $rule->priority,
            'is_active' => $rule->is_active,
        ];
    }
}
