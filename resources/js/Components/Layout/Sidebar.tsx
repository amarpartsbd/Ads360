import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Lock } from 'lucide-react';
import { cn } from '@/Utils/cn';

export interface NavItem {
    label: string;
    href?: string;
    icon: LucideIcon;
    /** Named permission required to see the item. */
    permission?: string;
    /** Modules that arrive in a later phase render as disabled rather than being hidden. */
    pending?: boolean;
    children?: NavItem[];
}

export interface NavSection {
    label?: string;
    items: NavItem[];
}

export function Sidebar({ sections, brand }: { sections: NavSection[]; brand: string }) {
    const currentUrl = usePage().url;

    return (
        <nav aria-label="Primary" className="flex h-full w-60 flex-col border-r border-border bg-surface">
            <div className="flex h-14 items-center border-b border-border px-5">
                <span className="text-sm font-semibold tracking-tight">{brand}</span>
            </div>

            <div className="flex-1 overflow-y-auto px-3 py-4">
                {sections.map((section, index) => (
                    <div key={section.label ?? index} className={cn(index > 0 && 'mt-6')}>
                        {section.label ? (
                            <p className="px-2 pb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                {section.label}
                            </p>
                        ) : null}
                        <ul className="space-y-0.5">
                            {section.items.map((item) => (
                                <li key={item.label}>
                                    <SidebarLink item={item} currentUrl={currentUrl} />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </nav>
    );
}

function SidebarLink({ item, currentUrl }: { item: NavItem; currentUrl: string }) {
    const Icon = item.icon;
    const active = item.href !== undefined && currentUrl.startsWith(item.href);

    const baseClass =
        'flex items-center gap-2.5 rounded-[var(--radius-control)] px-2.5 py-2 text-sm transition-colors';

    if (item.pending || item.href === undefined) {
        return (
            <span
                className={cn(baseClass, 'cursor-not-allowed text-muted-foreground/60')}
                title="Available in a later release"
                aria-disabled="true"
            >
                <Icon className="size-4 shrink-0" aria-hidden="true" />
                <span className="flex-1">{item.label}</span>
                <Lock className="size-3 shrink-0" aria-label="Not yet available" />
            </span>
        );
    }

    return (
        <Link
            href={item.href}
            aria-current={active ? 'page' : undefined}
            className={cn(
                baseClass,
                active
                    ? 'bg-secondary font-medium text-secondary-foreground'
                    : 'text-muted-foreground hover:bg-surface-muted hover:text-foreground',
            )}
        >
            <Icon className="size-4 shrink-0" aria-hidden="true" />
            {item.label}
        </Link>
    );
}
