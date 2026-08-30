<?php

declare(strict_types=1);

namespace App\Domains\Wallet\DTOs;

use App\Domains\Wallet\Enums\LedgerEntryType;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * One line to be written to the ledger.
 *
 * Several movements can be posted together as a single business event — a
 * campaign spend with its service fee and tax, for example — and the writer
 * gives them one transaction group.
 */
final readonly class LedgerMovement
{
    /**
     * @param  bool  $isCredit  true adds to available balance, false takes from it
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $rateSnapshot
     * @param  array<string, mixed>|null  $pricingSnapshot
     */
    public function __construct(
        public LedgerEntryType $type,
        public Money $amount,
        public bool $isCredit,
        public string $description,
        public ?Model $reference = null,
        public array $metadata = [],
        public ?array $rateSnapshot = null,
        public ?array $pricingSnapshot = null,
        /** Signed movement of the reserved balance; only reservations set it. */
        public int $reservedDelta = 0,
        public ?int $reversesEntryId = null,
    ) {}

    public static function credit(
        LedgerEntryType $type,
        Money $amount,
        string $description,
        ?Model $reference = null,
        array $metadata = [],
        ?array $rateSnapshot = null,
        ?array $pricingSnapshot = null,
    ): self {
        return new self(
            type: $type,
            amount: $amount,
            isCredit: true,
            description: $description,
            reference: $reference,
            metadata: $metadata,
            rateSnapshot: $rateSnapshot,
            pricingSnapshot: $pricingSnapshot,
        );
    }

    public static function debit(
        LedgerEntryType $type,
        Money $amount,
        string $description,
        ?Model $reference = null,
        array $metadata = [],
        ?array $rateSnapshot = null,
        ?array $pricingSnapshot = null,
    ): self {
        return new self(
            type: $type,
            amount: $amount,
            isCredit: false,
            description: $description,
            reference: $reference,
            metadata: $metadata,
            rateSnapshot: $rateSnapshot,
            pricingSnapshot: $pricingSnapshot,
        );
    }
}
