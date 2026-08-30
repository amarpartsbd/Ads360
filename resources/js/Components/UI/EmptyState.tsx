import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center">
            <span className="flex size-10 items-center justify-center rounded-full bg-surface-muted">
                <Icon className="size-5 text-muted-foreground" aria-hidden="true" />
            </span>
            <div className="space-y-1">
                <p className="text-sm font-medium">{title}</p>
                {description ? <p className="max-w-sm text-sm text-muted-foreground">{description}</p> : null}
            </div>
            {action}
        </div>
    );
}
