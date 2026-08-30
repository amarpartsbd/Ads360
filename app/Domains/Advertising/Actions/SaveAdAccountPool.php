<?php

declare(strict_types=1);

namespace App\Domains\Advertising\Actions;

use App\Domains\Advertising\Enums\PoolStatus;
use App\Domains\Advertising\Enums\Provider;
use App\Domains\Advertising\Enums\SelectionStrategy;
use App\Domains\Advertising\Exceptions\AdAccountException;
use App\Domains\Advertising\Models\AdAccountPool;
use App\Domains\Advertising\Values\AllocationRules;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Identity\Models\User;
use App\Support\Values\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and updates ad account pools (spec §18).
 *
 * Rules always pass through AllocationRules on the way in, so a malformed rule
 * is refused at the point an operator writes it rather than discovered later
 * by allocation, when the cost of a rule that silently stopped applying is a
 * client's money in the wrong account.
 *
 * Provider and currency are fixed at creation: changing either would leave the
 * pool holding members it can no longer compare.
 */
final class SaveAdAccountPool
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function create(
        string $name,
        Provider $provider,
        string $currency,
        SelectionStrategy $strategy,
        AllocationRules $rules,
        User $actor,
        ?string $description = null,
        int $priority = 50,
    ): AdAccountPool {
        $pool = DB::transaction(fn (): AdAccountPool => AdAccountPool::query()->create([
            'name' => trim($name),
            'slug' => $this->uniqueSlug($name),
            'description' => $description,
            'provider' => $provider,
            'currency' => Currency::of($currency)->code,
            'status' => PoolStatus::Draft,
            'allocation_rules' => $rules->toArray(),
            'selection_strategy' => $strategy,
            'priority' => max(0, min(100, $priority)),
            'created_by' => $actor->getKey(),
        ]));

        $this->audit->record(
            action: AuditAction::AdAccountPoolCreated,
            resource: $pool,
            after: [
                'name' => $pool->name,
                'provider' => $pool->provider->value,
                'currency' => $pool->currency,
                'selection_strategy' => $pool->selection_strategy->value,
                'allocation_rules' => $pool->allocation_rules,
            ],
            actor: $actor,
        );

        return $pool;
    }

    public function update(
        AdAccountPool $pool,
        User $actor,
        ?string $name = null,
        ?string $description = null,
        ?SelectionStrategy $strategy = null,
        ?AllocationRules $rules = null,
        ?int $priority = null,
    ): AdAccountPool {
        if ($pool->status === PoolStatus::Archived) {
            throw AdAccountException::poolNotEditable();
        }

        $before = AuditRecorder::snapshot($pool);

        DB::transaction(function () use ($pool, $name, $description, $strategy, $rules, $priority): void {
            if ($name !== null) {
                $pool->name = trim($name);
            }

            if ($description !== null) {
                $pool->description = $description;
            }

            if ($strategy !== null) {
                $pool->selection_strategy = $strategy;
            }

            if ($rules !== null) {
                $pool->setRules($rules);
            }

            if ($priority !== null) {
                $pool->priority = max(0, min(100, $priority));
            }

            $pool->save();
        });

        $this->audit->recordChange(
            action: AuditAction::AdAccountPoolUpdated,
            resource: $pool,
            before: $before,
            actor: $actor,
        );

        return $pool;
    }

    public function changeStatus(AdAccountPool $pool, PoolStatus $status, User $actor): AdAccountPool
    {
        $current = $pool->status;

        if ($current === $status) {
            return $pool;
        }

        if (! $current->canTransitionTo($status)) {
            throw new AdAccountException(sprintf(
                'A pool that is %s cannot be moved to %s.',
                strtolower($current->label()),
                strtolower($status->label()),
            ));
        }

        $before = AuditRecorder::snapshot($pool);

        DB::transaction(function () use ($pool, $status): void {
            $pool->status = $status;
            $pool->save();
        });

        $this->audit->recordChange(
            action: AuditAction::AdAccountPoolUpdated,
            resource: $pool,
            before: $before,
            context: ['from' => $current->value, 'to' => $status->value],
            actor: $actor,
        );

        return $pool;
    }

    /**
     * Slugs are only unique among pools that still exist; a soft-deleted pool
     * holding one hostage would force operators to invent names.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'pool';
        $slug = $base;
        $suffix = 2;

        while (AdAccountPool::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
