import { cn } from '@/Utils/cn';

/**
 * A monetary amount, as formatted by the server.
 *
 * Takes the already-formatted string rather than a number, because the browser
 * never formats or computes money — the server owns rounding, currency and
 * presentation (Rule 8, spec §60).
 */
export interface SerialisedMoney {
    amount: number;
    currency: string;
    decimal: string;
    formatted: string;
}

export function MoneyValue({
    value,
    className,
    tone = 'default',
}: {
    value: SerialisedMoney | string;
    className?: string;
    tone?: 'default' | 'credit' | 'debit' | 'muted';
}) {
    const formatted = typeof value === 'string' ? value : value.formatted;

    return (
        <span
            className={cn(
                'tabular-nums',
                tone === 'credit' && 'text-success',
                tone === 'debit' && 'text-foreground',
                tone === 'muted' && 'text-muted-foreground',
                className,
            )}
        >
            {formatted}
        </span>
    );
}
