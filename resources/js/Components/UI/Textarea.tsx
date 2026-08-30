import { forwardRef, type TextareaHTMLAttributes } from 'react';
import { cn } from '@/Utils/cn';

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(
    ({ className, rows = 4, ...props }, ref) => (
        <textarea
            ref={ref}
            rows={rows}
            className={cn(
                'flex w-full rounded-[var(--radius-control)] border border-input bg-surface px-3 py-2 text-sm shadow-xs transition-colors',
                'placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
                'aria-[invalid=true]:border-danger',
                className,
            )}
            {...props}
        />
    ),
);

Textarea.displayName = 'Textarea';
