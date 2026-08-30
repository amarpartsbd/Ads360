import { Link, router, usePage } from '@inertiajs/react';
import { Building2, ChevronDown, LogOut, Shield } from 'lucide-react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Button } from '@/Components/UI/Button';
import type { SharedProps } from '@/Types';
import { cn } from '@/Utils/cn';

export function Topbar({ title }: { title: string }) {
    const { auth, currentOrganization, organizations } = usePage<SharedProps>().props;

    const switchOrganization = (id: string) => {
        // The identifier is verified against the user's memberships server-side.
        router.post(route('client.organization.switch'), { organization: id }, { preserveScroll: true });
    };

    return (
        <header className="flex h-14 items-center justify-between gap-4 border-b border-border bg-surface px-6">
            <h1 className="truncate text-sm font-semibold tracking-tight">{title}</h1>

            <div className="flex items-center gap-2">
                {organizations.length > 1 && currentOrganization ? (
                    <DropdownMenu.Root>
                        <DropdownMenu.Trigger asChild>
                            <Button variant="outline" size="sm">
                                <Building2 aria-hidden="true" />
                                <span className="max-w-40 truncate">{currentOrganization.name}</span>
                                <ChevronDown aria-hidden="true" />
                            </Button>
                        </DropdownMenu.Trigger>
                        <DropdownMenu.Portal>
                            <DropdownMenu.Content
                                align="end"
                                sideOffset={6}
                                className="z-50 min-w-56 rounded-[var(--radius-card)] border border-border bg-surface p-1 shadow-md"
                            >
                                {organizations.map((item) => (
                                    <DropdownMenu.Item
                                        key={item.id}
                                        onSelect={() => switchOrganization(item.id)}
                                        className={cn(
                                            'cursor-pointer rounded-[var(--radius-control)] px-2.5 py-2 text-sm outline-none',
                                            'data-[highlighted]:bg-surface-muted',
                                            item.id === currentOrganization.id && 'font-medium',
                                        )}
                                    >
                                        {item.name}
                                    </DropdownMenu.Item>
                                ))}
                            </DropdownMenu.Content>
                        </DropdownMenu.Portal>
                    </DropdownMenu.Root>
                ) : null}

                <DropdownMenu.Root>
                    <DropdownMenu.Trigger asChild>
                        <Button variant="ghost" size="sm">
                            <span className="max-w-40 truncate">{auth.user?.name}</span>
                            <ChevronDown aria-hidden="true" />
                        </Button>
                    </DropdownMenu.Trigger>
                    <DropdownMenu.Portal>
                        <DropdownMenu.Content
                            align="end"
                            sideOffset={6}
                            className="z-50 min-w-52 rounded-[var(--radius-card)] border border-border bg-surface p-1 shadow-md"
                        >
                            <div className="px-2.5 py-2">
                                <p className="truncate text-sm font-medium">{auth.user?.name}</p>
                                <p className="truncate text-xs text-muted-foreground">{auth.user?.email}</p>
                            </div>
                            <DropdownMenu.Separator className="my-1 h-px bg-border" />

                            {!auth.user?.is_platform_user ? (
                                <DropdownMenu.Item asChild>
                                    <Link
                                        href={route('client.security.edit')}
                                        className="flex cursor-pointer items-center gap-2 rounded-[var(--radius-control)] px-2.5 py-2 text-sm outline-none data-[highlighted]:bg-surface-muted"
                                    >
                                        <Shield className="size-4" aria-hidden="true" />
                                        Security
                                    </Link>
                                </DropdownMenu.Item>
                            ) : null}

                            <DropdownMenu.Item
                                onSelect={() => router.post(route('logout'))}
                                className="flex cursor-pointer items-center gap-2 rounded-[var(--radius-control)] px-2.5 py-2 text-sm outline-none data-[highlighted]:bg-surface-muted"
                            >
                                <LogOut className="size-4" aria-hidden="true" />
                                Sign out
                            </DropdownMenu.Item>
                        </DropdownMenu.Content>
                    </DropdownMenu.Portal>
                </DropdownMenu.Root>
            </div>
        </header>
    );
}
