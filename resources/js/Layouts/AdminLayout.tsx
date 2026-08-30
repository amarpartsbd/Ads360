import { Head } from '@inertiajs/react';
import {
    Activity,
    Banknote,
    Building2,
    LayoutDashboard,
    Megaphone,
    ScrollText,
    Server,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { FlashMessages } from '@/Components/Layout/FlashMessages';
import { Sidebar, type NavSection } from '@/Components/Layout/Sidebar';
import { Topbar } from '@/Components/Layout/Topbar';
import { usePermissions } from '@/Hooks/usePermissions';

/**
 * The platform administration shell (spec §41).
 *
 * Items appear only when the signed-in administrator holds the matching
 * permission; the server authorizes each route regardless of what is rendered.
 */
export default function AdminLayout({
    title,
    description,
    actions,
    children,
}: {
    title: string;
    description?: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { can } = usePermissions();

    const sections: NavSection[] = [
        {
            items: [{ label: 'Dashboard', href: route('admin.dashboard'), icon: LayoutDashboard }],
        },
        {
            label: 'Clients',
            items: [
                ...(can('clients.view')
                    ? [{ label: 'All clients', href: route('admin.clients.index'), icon: Building2 }]
                    : []),
                { label: 'Agencies', icon: Users, pending: true },
                { label: 'Compliance', icon: ShieldCheck, pending: true },
            ],
        },
        {
            label: 'Operations',
            items: [
                { label: 'Campaign operations', icon: Megaphone, pending: true },
                { label: 'Ad infrastructure', icon: Server, pending: true },
                { label: 'Finance', icon: Banknote, pending: true },
                { label: 'Analytics', icon: Activity, pending: true },
            ],
        },
        {
            label: 'Governance',
            items: [
                ...(can('audit.view')
                    ? [{ label: 'Audit logs', href: route('admin.audit.index'), icon: ScrollText }]
                    : []),
                { label: 'Settings', icon: Settings, pending: true },
            ],
        },
    ];

    return (
        <div className="flex h-full min-h-screen bg-background">
            <Head title={title} />

            <div className="hidden md:block">
                <Sidebar sections={sections} brand="Ads360 Admin" />
            </div>

            <div className="flex min-w-0 flex-1 flex-col">
                <Topbar title={title} />

                <main className="flex-1 overflow-y-auto px-6 py-6">
                    <div className="mx-auto flex max-w-7xl flex-col gap-6">
                        {description || actions ? (
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                {description ? (
                                    <p className="max-w-2xl text-sm text-muted-foreground">{description}</p>
                                ) : (
                                    <span />
                                )}
                                {actions}
                            </div>
                        ) : null}

                        <FlashMessages />
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
