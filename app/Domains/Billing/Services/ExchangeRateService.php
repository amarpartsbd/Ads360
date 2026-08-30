<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Billing\Exceptions\MissingExchangeRate;
use App\Domains\Billing\Models\ExchangeRate;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Tenant;
use App\Support\Values\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and publishes exchange rates (spec §35).
 *
 * Resolution prefers a tenant's own rate card and falls back to the platform's.
 * Conversion always returns the rate alongside the amount, so the caller can
 * store the snapshot with the transaction — a converted amount without the rate
 * that produced it is unauditable.
 */
final class ExchangeRateService
{
    /**
     * The rate in force for a pair at a point in time.
     *
     * `$at` matters: reconciling a three-week-old provider invoice must use the
     * rate that applied then, not today's.
     */
    public function resolve(
        string $base,
        string $quote,
        ?Tenant $tenant = null,
        ?Carbon $at = null,
    ): ExchangeRate {
        $at ??= Carbon::now();

        $rate = $this->query($base, $quote, $tenant?->getKey(), $at);

        // Fall back to the platform card when the tenant has no rate of its own.
        if ($rate === null && $tenant !== null) {
            $rate = $this->query($base, $quote, null, $at);
        }

        return $rate ?? throw MissingExchangeRate::forPair($base, $quote, $at->toIso8601String());
    }

    public function hasRate(string $base, string $quote, ?Tenant $tenant = null, ?Carbon $at = null): bool
    {
        try {
            $this->resolve($base, $quote, $tenant, $at);

            return true;
        } catch (MissingExchangeRate) {
            return false;
        }
    }

    /**
     * Convert an amount, returning the result and the rate that produced it.
     *
     * @return array{amount: Money, rate: ExchangeRate, snapshot: array<string, string|null>}
     */
    public function convert(
        Money $amount,
        string $quote,
        ?Tenant $tenant = null,
        ?Carbon $at = null,
    ): array {
        if ($amount->currency->code === $quote) {
            throw new \InvalidArgumentException(
                'Converting an amount to its own currency is a mistake, not a conversion.'
            );
        }

        $rate = $this->resolve($amount->currency->code, $quote, $tenant, $at);

        return [
            'amount' => $rate->convert($amount),
            'rate' => $rate,
            'snapshot' => $rate->snapshot(),
        ];
    }

    /**
     * Publish a new rate for a pair.
     *
     * The previous rate is closed off at the moment the new one starts rather
     * than edited, so every historical transaction still points at a row that
     * describes the terms it was made under.
     */
    public function publish(
        string $base,
        string $quote,
        string $marketRate,
        string $clientRate,
        ?Tenant $tenant = null,
        ?Carbon $effectiveFrom = null,
        ?User $actor = null,
        string $source = 'MANUAL',
        ?string $note = null,
    ): ExchangeRate {
        $effectiveFrom ??= Carbon::now();

        return DB::transaction(function () use (
            $base,
            $quote,
            $marketRate,
            $clientRate,
            $tenant,
            $effectiveFrom,
            $actor,
            $source,
            $note,
        ): ExchangeRate {
            ExchangeRate::query()
                ->where('base_currency', $base)
                ->where('quote_currency', $quote)
                ->when(
                    $tenant === null,
                    fn ($query) => $query->whereNull('tenant_id'),
                    fn ($query) => $query->where('tenant_id', $tenant->getKey()),
                )
                ->whereNull('effective_until')
                ->lockForUpdate()
                ->update(['effective_until' => $effectiveFrom, 'updated_at' => Carbon::now()]);

            return ExchangeRate::query()->create([
                'tenant_id' => $tenant?->getKey(),
                'base_currency' => $base,
                'quote_currency' => $quote,
                'market_rate' => $marketRate,
                'client_rate' => $clientRate,
                'effective_from' => $effectiveFrom,
                'source' => $source,
                'note' => $note,
                'created_by' => $actor?->getKey(),
            ]);
        });
    }

    private function query(string $base, string $quote, ?int $tenantId, Carbon $at): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->when(
                $tenantId === null,
                fn ($query) => $query->whereNull('tenant_id'),
                fn ($query) => $query->where('tenant_id', $tenantId),
            )
            ->where('effective_from', '<=', $at)
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
