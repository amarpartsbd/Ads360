import { Head } from '@inertiajs/react';
import {
    Activity,
    ArrowLeftRight,
    Banknote,
    Building2,
    Layers,
    LayoutDashboard,
    Megaphone,
    ScrollText,
    Server,
    Settings,
    ShieldCheck,
    Tag,
    Users,
    Wallet,
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
                ...(can('clients.view')
                    ? [{ label: 'Agencies', href: route('admin.agencies.index'), icon: Users }]
                    : []),
                ...(can('clients.verify')
                    ? [
                          {
                              label: 'Compliance',
                              href: route('admin.verification.index'),
                              icon: ShieldCheck,
                          },
                      ]
                    : []),
            ],
        },
        {
            label: 'Operations',
            items: [
                ...(can('campaigns.view')
                    ? [
                          {
                              label: 'Campaign review',
                              href: route('admin.campaigns.index'),
                              icon: Megaphone,
                          },
                      ]
                    : []),
                ...(can('ad_accounts.view')
                    ? [
                          {
                              label: 'Ad accounts',
                              href: route('admin.ad-accounts.index'),
                              icon: Server,
                          },
                          {
                              label: 'Account pools',
                              href: route('admin.ad-account-pools.index'),
                              icon: Layers,
                          },
                      ]
                    : []),
                ...(can('reports.view')
                    ? [{ label: 'Analytics', href: route('admin.analytics.overview'), icon: Activity }]
                    : []),
            ],
        },
        {
            label: 'Finance',
            items: [
                ...(can('payments.verify')
                    ? [
                          {
                              label: 'Deposits',
                              href: route('admin.finance.deposits.index'),
                              icon: Banknote,
                          },
                      ]
                    : []),
                ...(can('wallet.view')
                    ? [{ label: 'Wallets', href: route('admin.finance.wallets.index'), icon: Wallet }]
                    : []),
                ...(can('wallet.adjust') || can('wallet.refund')
                    ? [
                          {
                              label: 'Approvals',
                              href: route('admin.finance.approvals.index'),
                              icon: ShieldCheck,
                          },
                      ]
                    : []),
                ...(can('exchange_rates.view')
                    ? [
                          {
                              label: 'Exchange rates',
                              href: route('admin.finance.exchange-rates.index'),
                              icon: ArrowLeftRight,
                          },
                      ]
                    : []),
                ...(can('pricing.view')
                    ? [{ label: 'Pricing', href: route('admin.finance.pricing.index'), icon: Tag }]
                    : []),
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
