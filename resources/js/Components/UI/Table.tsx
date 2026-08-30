import type { HTMLAttributes, ReactNode, ThHTMLAttributes } from 'react';
import { cn } from '@/Utils/cn';

/**
 * Wide tables scroll inside their own container rather than making the page
 * scroll sideways (spec §71, §73).
 */
export function Table({ children, caption }: { children: ReactNode; caption?: string }) {
    return (
        <div className="w-full overflow-x-auto">
            <table className="w-full caption-bottom text-sm">
                {caption ? <caption className="sr-only">{caption}</caption> : null}
                {children}
            </table>
        </div>
    );
}

export function Th({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            scope="col"
            className={cn(
                'border-b border-border px-4 py-2.5 text-left text-xs font-medium tracking-wide text-muted-foreground uppercase',
                className,
            )}
            {...props}
        />
    );
}

export function Td({ className, ...props }: HTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('border-b border-border px-4 py-3 align-middle', className)} {...props} />;
}
