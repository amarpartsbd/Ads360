import { Head } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    CreditCard,
    FileText,
    Image,
    LayoutDashboard,
    LifeBuoy,
    Megaphone,
    Plug,
    Settings,
    ShieldCheck,
    Users,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { FlashMessages } from '@/Components/Layout/FlashMessages';
import { Sidebar, type NavSection } from '@/Components/Layout/Sidebar';
import { Topbar } from '@/Components/Layout/Topbar';
import { usePermissions } from '@/Hooks/usePermissions';

/**
 * The client and agency application shell (spec §14).
 *
 * Navigation for modules that land in later phases is shown as unavailable
 * rather than hidden, so the shape of the product is legible while the routes
 * do not yet exist.
 */
export default function ClientLayout({
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
            items: [
                { label: 'Dashboard', href: route('client.dashboard'), icon: LayoutDashboard },
                { label: 'Verification', href: route('client.verification.show'), icon: ShieldCheck },
            ],
        },
        {
            label: 'Advertising',
            items: [
                { label: 'Campaigns', icon: Megaphone, pending: true },
                { label: 'Advertising assets', icon: Plug, pending: true },
                { label: 'Creative library', icon: Image, pending: true },
            ],
        },
        {
            label: 'Finance',
            items: [
                ...(can('wallet.view')
                    ? [
                          { label: 'Wallet', href: route('client.wallet.overview'), icon: Wallet },
                          {
                              label: 'Statement',
                              href: route('client.wallet.transactions'),
                              icon: FileText,
                          },
                      ]
                    : []),
                ...(can('payments.view')
                    ? [{ label: 'Invoices', href: route('client.wallet.invoices'), icon: CreditCard }]
                    : []),
            ],
        },
        {
            label: 'Insights',
            items: [{ label: 'Analytics', icon: BarChart3, pending: true }],
        },
        {
            label: 'Workspace',
            items: [
                ...(can('users.manage')
                    ? [{ label: 'Team', href: route('client.team.index'), icon: Users }]
                    : []),
                { label: 'Support', icon: LifeBuoy, pending: true },
                { label: 'Organization', href: route('client.settings.organization'), icon: Building2 },
                { label: 'Security', href: route('client.security.edit'), icon: Settings },
            ],
        },
    ];

    return (
        <div className="flex h-full min-h-screen bg-background">
            <Head title={title} />

            <div className="hidden md:block">
                <Sidebar sections={sections} brand="Ads360" />
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
