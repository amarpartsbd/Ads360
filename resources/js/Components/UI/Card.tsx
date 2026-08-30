import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/Utils/cn';

export function Card({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            className={cn('rounded-[var(--radius-card)] border border-border bg-surface', className)}
            {...props}
        />
    );
}

export function CardHeader({
    title,
    description,
    action,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex items-start justify-between gap-4 border-b border-border px-5 py-4',
                className,
            )}
        >
            <div className="space-y-1">
                <h2 className="text-sm font-semibold tracking-tight">{title}</h2>
                {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
            </div>
            {action}
        </div>
    );
}

export function CardBody({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('px-5 py-4', className)} {...props} />;
}
