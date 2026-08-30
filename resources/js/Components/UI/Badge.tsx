import { cva, type VariantProps } from 'class-variance-authority';
import type { ReactNode } from 'react';
import { cn } from '@/Utils/cn';

const badgeVariants = cva(
    'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium',
    {
        variants: {
            tone: {
                neutral: 'border-border bg-surface-muted text-muted-foreground',
                success: 'border-transparent bg-success-subtle text-success',
                warning: 'border-transparent bg-warning-subtle text-warning-foreground',
                danger: 'border-transparent bg-danger-subtle text-danger',
                info: 'border-transparent bg-info-subtle text-info',
            },
        },
        defaultVariants: { tone: 'neutral' },
    },
);

export interface BadgeProps extends VariantProps<typeof badgeVariants> {
    children: ReactNode;
    className?: string;
    /**
     * A short glyph rendered before the label. Status must never be carried by
     * colour alone (spec §74), so every coloured badge should pass one.
     */
    icon?: ReactNode;
}

export function Badge({ tone, icon, className, children }: BadgeProps) {
    return (
        <span className={cn(badgeVariants({ tone }), className)}>
            {icon}
            {children}
        </span>
    );
}
