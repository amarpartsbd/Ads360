import { Link, router } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Input } from '@/Components/UI/Input';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface ClientRow {
    id: string;
    name: string;
    tenant: string;
    tenantType: string;
    status: string;
    statusLabel: string;
    country: string | null;
    createdAt: string | null;
}

export default function ClientsIndex({
    organizations,
    filters,
}: {
    organizations: Paginated<ClientRow>;
    filters: { search: string; status: string | null };
}) {
    const [search, setSearch] = useState(filters.search);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // Filtering happens on the server; the browser never holds the full table.
        router.get(route('admin.clients.index'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Clients" description="Every client organization across all tenants.">
            <Card>
                <CardHeader
                    title="Organizations"
                    description={`${organizations.total} total.`}
                    action={
                        <form onSubmit={submit} className="flex gap-2">
                            <label htmlFor="client-search" className="sr-only">
                                Search organizations
                            </label>
                            <Input
                                id="client-search"
                                type="search"
                                placeholder="Search by name or email"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="w-56"
                            />
                            <Button type="submit" variant="outline" size="md">
                                Search
                            </Button>
                        </form>
                    }
                />

                {organizations.data.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No organizations match"
                        description="Try a different search term."
                    />
                ) : (
                    <>
                        <Table caption="Client organizations">
                            <thead>
                                <tr>
                                    <Th>Organization</Th>
                                    <Th>Tenant</Th>
                                    <Th>Status</Th>
                                    <Th>Country</Th>
                                    <Th>Registered</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {organizations.data.map((organization) => (
                                    <tr key={organization.id}>
                                        <Td>
                                            <Link
                                                href={route('admin.clients.show', organization.id)}
                                                className="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                {organization.name}
                                            </Link>
                                        </Td>
                                        <Td className="text-muted-foreground">
                                            {organization.tenant}
                                            <Badge className="ml-2">{organization.tenantType}</Badge>
                                        </Td>
                                        <Td>
                                            <Badge
                                                tone={
                                                    organization.status === 'ACTIVE' ? 'success' : 'warning'
                                                }
                                            >
                                                {organization.statusLabel}
                                            </Badge>
                                        </Td>
                                        <Td className="text-muted-foreground">
                                            {organization.country ?? '—'}
                                        </Td>
                                        <Td className="text-muted-foreground">
                                            {organization.createdAt
                                                ? new Date(organization.createdAt).toLocaleDateString()
                                                : '—'}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>

                        <Pagination paginated={organizations} />
                    </>
                )}
            </Card>
        </AdminLayout>
    );
}

function Pagination({ paginated }: { paginated: Paginated<ClientRow> }) {
    if (paginated.last_page <= 1) {
        return null;
    }

    return (
        <nav aria-label="Pagination" className="flex items-center justify-between gap-4 px-5 py-3">
            <p className="text-sm text-muted-foreground">
                Showing {paginated.from ?? 0}–{paginated.to ?? 0} of {paginated.total}
            </p>
            <div className="flex flex-wrap gap-1">
                {paginated.links.map((link, index) => (
                    <Button
                        key={index}
                        variant={link.active ? 'secondary' : 'ghost'}
                        size="sm"
                        disabled={link.url === null}
                        onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                        aria-current={link.active ? 'page' : undefined}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>
        </nav>
    );
}
