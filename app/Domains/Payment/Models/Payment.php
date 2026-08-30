<?php

declare(strict_types=1);

namespace App\Domains\Payment\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\PaymentMethod;
use App\Domains\Payment\Enums\PaymentStatus;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Wallet\Models\LedgerEntry;
use App\Domains\Wallet\Models\Wallet;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment into a wallet (spec §33, §34).
 *
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToTenant;

    use HasFactory;
    use HasPublicId;

    protected $fillable = [
        'organization_id',
        'wallet_id',
        'reference',
        'method',
        'provider',
        'amount',
        'currency',
        'status',
        'idempotency_key',
        'external_reference',
        'provider_reference',
        'submitted_at',
        'paid_at',
        'metadata',
        'created_by',
    ];

    /**
     * Where the proof of payment physically lives never leaves the server, for
     * the same reason a KYC document's path does not (spec §55).
     *
     * @var list<string>
     */
    protected $hidden = ['proof_disk', 'proof_path', 'idempotency_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<LedgerEntry, $this>
     */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class, 'ledger_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountMoney(): Money
    {
        return Money::ofMinor($this->amount, $this->currency);
    }

    public function hasProof(): bool
    {
        return $this->proof_path !== null;
    }

    public function isSettled(): bool
    {
        return $this->status->isSettled();
    }

    /**
     * A short, human-quotable reference. Random rather than sequential so it
     * reveals nothing about how many payments the platform has taken.
     */
    public static function generateReference(): string
    {
        return 'PAY-'.strtoupper(bin2hex(random_bytes(6)));
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
