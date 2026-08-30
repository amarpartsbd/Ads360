<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Billing\DTOs\PricedAmount;
use App\Domains\Billing\Enums\FeeType;
use App\Domains\Billing\Enums\InvoiceKind;
use App\Domains\Billing\Enums\InvoiceStatus;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Models\User;
use App\Domains\Tenant\Models\Organization;
use App\Support\Values\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates and finalises invoices (spec §37).
 *
 * A finalised invoice is immutable. Correcting one is a void plus a credit
 * note that points back at it, so what the client received stays reproducible
 * (spec §62).
 */
final class InvoiceService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * Build a draft from a priced amount.
     *
     * The billing details are copied from the organization at this moment so
     * the document still renders correctly after the client moves office.
     */
    public function draftFor(
        Organization $organization,
        PricedAmount $priced,
        string $description,
        ?User $actor = null,
    ): Invoice {
        return DB::transaction(function () use ($organization, $priced, $description, $actor): Invoice {
            $invoice = new Invoice([
                'organization_id' => $organization->getKey(),
                'number' => $this->reserveNumber(),
                'kind' => InvoiceKind::Invoice,
                'status' => InvoiceStatus::Draft,
                'currency' => $priced->total->currency->code,
                'billing_name' => $organization->legal_name ?? $organization->name,
                'billing_email' => $organization->contact_email,
                'billing_address' => $organization->country,
                'pricing_snapshot' => $priced->pricingSnapshot,
                'created_by' => $actor?->getKey(),
            ]);
            $invoice->tenant_id = $organization->tenant_id;
            $invoice->save();

            $position = 0;

            $invoice->lines()->create([
                'description' => $description,
                'quantity_milli' => 1000,
                'unit_amount' => $priced->base->minorUnits,
                'line_total' => $priced->base->minorUnits,
                'position' => $position++,
            ]);

            $taxTotal = 0;

            foreach ($priced->fees as $fee) {
                if ($fee->type === FeeType::Tax) {
                    $taxTotal += $fee->amount->minorUnits;

                    continue;
                }

                $invoice->lines()->create([
                    'description' => $fee->description,
                    'fee_type' => $fee->type,
                    'quantity_milli' => 1000,
                    'unit_amount' => $fee->amount->minorUnits,
                    'line_total' => $fee->amount->minorUnits,
                    'position' => $position++,
                    'metadata' => ['rule' => $fee->ruleSnapshot],
                ]);
            }

            $this->recalculate($invoice, $taxTotal);

            return $invoice;
        });
    }

    /**
     * Finalise a draft and make it visible to the client.
     */
    public function issue(Invoice $invoice, ?User $actor = null): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new RuntimeException("Invoice {$invoice->number} has already been issued.");
        }

        $invoice->forceFill([
            'status' => InvoiceStatus::Issued,
            'issued_on' => Carbon::now()->toDateString(),
            'issued_at' => Carbon::now(),
            'due_on' => Carbon::now()->addDays((int) config('platform.finance.invoice_due_days'))->toDateString(),
        ])->save();

        $this->audit->record(
            action: AuditAction::InvoiceIssued,
            resource: $invoice,
            after: ['number' => $invoice->number, 'total' => $invoice->totalMoney()->toDecimal()],
            organization: $invoice->organization()->withoutGlobalScopes()->first(),
            actor: $actor,
        );

        return $invoice;
    }

    /**
     * Void an invoice and issue a credit note for it.
     *
     * Both documents survive: the original shows what was billed, the credit
     * note shows it being undone, and the client's records reconcile.
     */
    public function voidWithCreditNote(Invoice $invoice, string $reason, ?User $actor = null): Invoice
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw new RuntimeException("Invoice {$invoice->number} is already void.");
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            $creditNote = new Invoice([
                'organization_id' => $invoice->organization_id,
                'number' => $this->reserveNumber(),
                'kind' => InvoiceKind::CreditNote,
                'status' => InvoiceStatus::Draft,
                'currency' => $invoice->currency,
                'billing_name' => $invoice->billing_name,
                'billing_email' => $invoice->billing_email,
                'billing_address' => $invoice->billing_address,
                'tax_identifier' => $invoice->tax_identifier,
                'corrects_invoice_id' => $invoice->getKey(),
                'pricing_snapshot' => $invoice->pricing_snapshot,
                'metadata' => ['reason' => $reason],
                'created_by' => $actor?->getKey(),
            ]);
            $creditNote->tenant_id = $invoice->tenant_id;
            $creditNote->save();

            $position = 0;

            foreach ($invoice->lines as $line) {
                $creditNote->lines()->create([
                    'description' => 'Credit: '.$line->description,
                    'fee_type' => $line->fee_type,
                    'quantity_milli' => $line->quantity_milli,
                    'unit_amount' => $line->unit_amount,
                    'line_total' => $line->line_total,
                    'tax_amount' => $line->tax_amount,
                    'position' => $position++,
                ]);
            }

            $this->recalculate($creditNote, $invoice->tax_total);
            $this->issue($creditNote, $actor);

            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => Carbon::now(),
                'void_reason' => $reason,
            ])->save();

            $this->audit->record(
                action: AuditAction::InvoiceVoided,
                resource: $invoice,
                after: ['status' => InvoiceStatus::Void->value, 'credit_note' => $creditNote->number],
                context: ['reason' => $reason],
                organization: $invoice->organization()->withoutGlobalScopes()->first(),
                actor: $actor,
            );

            return $creditNote;
        });
    }

    /**
     * Record a payment against an invoice and move its status accordingly.
     */
    public function recordPayment(Invoice $invoice, Money $amount, ?User $actor = null): Invoice
    {
        return DB::transaction(function () use ($invoice, $amount): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()
                ->withoutGlobalScopes()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $paid = $locked->amount_paid + $amount->minorUnits;
            $status = $paid >= $locked->total ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid;

            if (! $locked->status->canTransitionTo($status)) {
                throw new RuntimeException(
                    "Invoice {$locked->number} cannot move from {$locked->status->value} to {$status->value}."
                );
            }

            $locked->forceFill([
                'amount_paid' => $paid,
                'status' => $status,
                'paid_at' => $status === InvoiceStatus::Paid ? Carbon::now() : null,
            ])->save();

            $invoice->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Recompute the totals from the lines. Only ever called on a draft.
     */
    private function recalculate(Invoice $invoice, int $taxTotal = 0): void
    {
        $invoice->load('lines');

        $subtotal = (int) $invoice->lines->sum('line_total');

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'tax_total' => $taxTotal,
            'total' => $subtotal + $taxTotal - $invoice->discount_total,
        ])->save();
    }

    /**
     * Reserve the next number for this year.
     *
     * Serialised with an advisory lock rather than a table lock: two invoices
     * created at the same instant must not take the same number, and an
     * advisory lock is cheap and released with the transaction.
     */
    private function reserveNumber(): string
    {
        $year = (int) Carbon::now()->year;

        DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('invoice-number-'.$year)]);

        $used = Invoice::query()
            ->withoutGlobalScopes()
            ->where('number', 'like', config('platform.finance.invoice_prefix').'-'.$year.'-%')
            ->count();

        return Invoice::nextNumber($year, $used + 1);
    }
}
