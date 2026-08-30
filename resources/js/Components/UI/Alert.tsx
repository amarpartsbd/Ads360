import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/Utils/cn';

type Tone = 'success' | 'warning' | 'danger' | 'info';

const TONES: Record<Tone, { className: string; Icon: typeof Info; label: string }> = {
    success: {
        className: 'border-success/30 bg-success-subtle text-foreground',
        Icon: CheckCircle2,
        label: 'Success',
    },
    warning: {
        className: 'border-warning/40 bg-warning-subtle text-foreground',
        Icon: AlertTriangle,
        label: 'Warning',
    },
    danger: { className: 'border-danger/30 bg-danger-subtle text-foreground', Icon: XCircle, label: 'Error' },
    info: { className: 'border-info/30 bg-info-subtle text-foreground', Icon: Info, label: 'Information' },
};

/**
 * Every alert carries an icon and a visually hidden label, so the meaning does
 * not depend on colour (spec §74).
 */
export function Alert({ tone, title, children }: { tone: Tone; title?: string; children: ReactNode }) {
    const { className, Icon, label } = TONES[tone];

    return (
        <div
            role={tone === 'danger' ? 'alert' : 'status'}
            className={cn('flex gap-3 rounded-[var(--radius-card)] border px-4 py-3 text-sm', className)}
        >
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div className="space-y-0.5">
                <span className="sr-only">{label}: </span>
                {title ? <p className="font-medium">{title}</p> : null}
                <div className="text-muted-foreground">{children}</div>
            </div>
        </div>
    );
}
