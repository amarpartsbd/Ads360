import type { ReactNode } from 'react';
import { cn } from '@/Utils/cn';

/**
 * A single headline figure.
 *
 * Not a chart: one number has no shape to show, and drawing it as one would
 * add ink without adding information. The comparison, when there is one, is a
 * word and a percentage rather than a coloured arrow alone — colour never
 * carries the meaning on its own (spec §74).
 */
export function StatTile({
    label,
    value,
    hint,
    change,
    changeDirection,
}: {
    label: string;
    value: string;
    hint?: ReactNode;
    change?: string | null;
    changeDirection?: 'up' | 'down' | null;
}) {
    return (
        <div className="rounded-[var(--radius-card)] border border-border bg-surface px-5 py-4">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
            <p className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{value}</p>

            {change ? (
                <p
                    className={cn(
                        'mt-1 text-xs font-medium',
                        changeDirection === 'up' && 'text-success',
                        changeDirection === 'down' && 'text-danger',
                        !changeDirection && 'text-muted-foreground',
                    )}
                >
                    {changeDirection === 'up' ? 'Up' : changeDirection === 'down' ? 'Down' : 'Change'}{' '}
                    {change}% on the previous period
                </p>
            ) : null}

            {hint ? <p className="mt-1 text-xs text-muted-foreground">{hint}</p> : null}
        </div>
    );
}
