<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\InvoiceKind;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Concerns\BelongsToTenant;
use App\Domains\Tenant\Models\Organization;
use App\Support\Concerns\HasPublicId;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * An invoice or credit note (spec §37).
 *
 * Once finalised the model refuses to change its own financial fields. Guarding
 * it here as well as in the service means a stray `update()` anywhere in the
 * application cannot quietly rewrite a document a client already holds.
 *
 * @property InvoiceStatus $status
 * @property InvoiceKind $kind
 * @property Collection<int, InvoiceLine> $lines
 */
class Invoice extends Model
{
    use BelongsToTenant;
    use HasPublicId;

    /**
     * Fields that describe the money owed. Frozen once the document is issued.
     *
     * @var list<string>
     */
    private const FINANCIAL_FIELDS = [
        'currency', 'subtotal', 'tax_total', 'discount_total', 'total',
        'billing_name', 'billing_address', 'billing_email', 'tax_identifier',
        'issued_on', 'kind', 'organization_id',
    ];

    protected $fillable = [
        'organization_id',
        'number',
        'kind',
        'status',
        'currency',
        'billing_name',
        'billing_address',
        'billing_email',
        'tax_identifier',
        'issued_on',
        'due_on',
        'corrects_invoice_id',
        'pricing_snapshot',
        'metadata',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'subtotal' => 'integer',
            'tax_total' => 'integer',
            'discount_total' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'issued_on' => 'immutable_date',
            'due_on' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'pricing_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            // The status the row had before this save decides whether it was
            // already finalised; moving Draft → Issued is exactly the write
            // that must be allowed.
            //
            // getRawOriginal, not getOriginal: the latter returns the value
            // already cast to the enum, which cannot be re-parsed from a string.
            $wasFinalised = InvoiceStatus::from(
                (string) $invoice->getRawOriginal('status')
            )->isFinalised();

            if (! $wasFinalised) {
                return;
            }

            foreach (self::FINANCIAL_FIELDS as $field) {
                if ($invoice->isDirty($field)) {
                    throw new RuntimeException(
                        "Invoice {$invoice->number} is finalised; [{$field}] cannot be changed. "
                        .'Void it and issue a credit note instead.'
                    );
                }
            }
        });

        static::deleting(function (self $invoice): never {
            throw new RuntimeException('Invoices are never deleted. Void them instead.');
        });
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function correctedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_invoice_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalMoney(): Money
    {
        return Money::ofMinor($this->total, $this->currency);
    }

    public function outstanding(): Money
    {
        return Money::ofMinor(max(0, $this->total - $this->amount_paid), $this->currency);
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null
            && $this->due_on->isPast()
            && ! $this->status->isSettled();
    }

    /**
     * Sequential per year, zero-padded, with a configurable prefix.
     * Callers must hold a lock while generating, or two invoices race for the
     * same number — InvoiceService does exactly that.
     */
    public static function nextNumber(int $year, int $sequence): string
    {
        return sprintf('%s-%d-%05d', config('platform.finance.invoice_prefix'), $year, $sequence);
    }
}
