import { forwardRef, type SelectHTMLAttributes } from 'react';
import { cn } from '@/Utils/cn';

/**
 * A native select. Enterprise forms are keyboard- and screen-reader-heavy, and
 * the platform select beats a custom listbox on both counts (spec §74).
 */
export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(
    ({ className, children, ...props }, ref) => (
        <select
            ref={ref}
            className={cn(
                'flex h-9 w-full rounded-[var(--radius-control)] border border-input bg-surface px-3 text-sm shadow-xs transition-colors',
                'disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-danger',
                className,
            )}
            {...props}
        >
            {children}
        </select>
    ),
);

Select.displayName = 'Select';
