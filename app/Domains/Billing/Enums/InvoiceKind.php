<?php

declare(strict_types=1);

namespace App\Domains\Billing\Enums;

enum InvoiceKind: string
{
    case Invoice = 'INVOICE';
    case CreditNote = 'CREDIT_NOTE';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::CreditNote => 'Credit note',
        };
    }
}
