<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Enums\FeeType;
use App\Support\Values\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on an invoice (spec §37).
 *
 * @property int $quantity_milli
 */
class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'description',
        'fee_type',
        'quantity_milli',
        'unit_amount',
        'line_total',
        'tax_amount',
        'position',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_type' => FeeType::class,
            'quantity_milli' => 'integer',
            'unit_amount' => 'integer',
            'line_total' => 'integer',
            'tax_amount' => 'integer',
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lineTotal(string $currency): Money
    {
        return Money::ofMinor($this->line_total, $currency);
    }

    /** Quantity as a readable decimal — 1500 thousandths reads as "1.5". */
    public function quantity(): string
    {
        return rtrim(rtrim(number_format($this->quantity_milli / 1000, 3, '.', ''), '0'), '.');
    }
}
