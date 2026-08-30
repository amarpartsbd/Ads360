<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Models;

use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Values\AllocationRules;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Currency;
use Database\Factories\AdAccountPoolFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named group of managed ad accounts, with the rules for drawing from it
 * (spec §18).
 *
 * The rules are stored as a document and always read through AllocationRules,
 * so a malformed stored rule fails loudly instead of quietly not applying.
 *
 * @property Provider $provider
 * @property PoolStatus $status
 * @property SelectionStrategy $selection_strategy
 * @property int $priority
 */
class AdAccountPool extends Model
{
    /** @use HasFactory<AdAccountPoolFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'provider',
        'currency',
        'status',
        'allocation_rules',
        'selection_strategy',
        'priority',
        'created_by',
    ];

    /** @var array<string, int> */
    protected $attributes = [
        'priority' => 50,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => PoolStatus::class,
            'selection_strategy' => SelectionStrategy::class,
            'allocation_rules' => 'array',
            'priority' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<AdAccount, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(AdAccount::class, 'ad_account_pool_members')
            ->withPivot(['weight', 'added_by'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<AdAccountPoolMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(AdAccountPoolMember::class);
    }

    public function currency(): Currency
    {
        return Currency::of($this->currency);
    }

    public function rules(): AllocationRules
    {
        return AllocationRules::fromArray($this->allocation_rules ?? []);
    }

    public function setRules(AllocationRules $rules): void
    {
        $this->allocation_rules = $rules->toArray();
    }

    public function isAllocatable(): bool
    {
        return $this->status->isAllocatable() && $this->deleted_at === null;
    }

    /**
     * Whether an account is even the right shape for this pool. Provider and
     * currency have to match before any rule is worth evaluating: a pool
     * cannot hold a Google account beside a Meta one, nor mix currencies,
     * because allocation would then be comparing amounts that are not
     * comparable.
     */
    public function accepts(AdAccount $account): bool
    {
        return $account->provider === $this->provider
            && strtoupper($account->currency) === strtoupper($this->currency);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAllocatable(Builder $query): Builder
    {
        return $query->where('status', PoolStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProvider(Builder $query, Provider $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    protected static function newFactory(): AdAccountPoolFactory
    {
        return AdAccountPoolFactory::new();
    }
}
