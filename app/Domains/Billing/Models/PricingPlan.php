<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\PricingScope;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Concerns\HasPublicId;
use Database\Factories\PricingPlanFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of fee rules (spec §36).
 *
 * Not tenant-scoped globally: the platform default has no tenant and must stay
 * visible to every resolution. Policies decide who may read or change one.
 *
 * @property PricingScope $scope
 * @property Collection<int, PricingRule> $rules
 */
class PricingPlan extends Model
{
    /** @use HasFactory<PricingPlanFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'name',
        'description',
        'scope',
        'tenant_id',
        'organization_id',
        'currency',
        'is_active',
        'is_default',
        'effective_from',
        'effective_until',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => PricingScope::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<PricingRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The snapshot stored with every charge computed from this plan, so an old
     * invoice explains itself without depending on the plan still existing in
     * its original form (spec §36).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'plan_id' => $this->public_id,
            'plan_name' => $this->name,
            'scope' => $this->scope->value,
            'currency' => $this->currency,
            'rules' => $this->rules
                ->map(fn (PricingRule $rule): array => $rule->snapshot())
                ->values()
                ->all(),
        ];
    }

    protected static function newFactory(): PricingPlanFactory
    {
        return PricingPlanFactory::new();
    }
}
