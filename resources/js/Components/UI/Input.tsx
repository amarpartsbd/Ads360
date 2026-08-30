import { forwardRef, type InputHTMLAttributes } from 'react';
import { cn } from '@/Utils/cn';

export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(
    ({ className, ...props }, ref) => (
        <input
            ref={ref}
            className={cn(
                'flex h-9 w-full rounded-[var(--radius-control)] border border-input bg-surface px-3 py-1 text-sm shadow-xs transition-colors',
                'placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
                'aria-[invalid=true]:border-danger',
                className,
            )}
            {...props}
        />
    ),
);

Input.displayName = 'Input';
