import { Link, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Button } from '@/Components/UI/Button';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface QueueRow {
    id: string;
    organization: string;
    tenant: string;
    legalName: string;
    status: string;
    statusLabel: string;
    submittedAt: string | null;
    waitingDays: number | null;
}

export default function VerificationIndex({
    profiles,
    filters,
    statuses,
    counts,
}: {
    profiles: Paginated<QueueRow>;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
    counts: Record<string, number>;
}) {
    return (
        <AdminLayout title="Compliance" description="Business verification submissions awaiting a decision.">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <Count label="Pending" value={counts.pending ?? 0} />
                <Count label="Under review" value={counts.underReview ?? 0} />
                <Count label="Awaiting client" value={counts.requiresInformation ?? 0} />
                <Count label="Verified" value={counts.verified ?? 0} />
                <Count label="Rejected" value={counts.rejected ?? 0} />
            </div>

            <Card>
                <CardHeader
                    title="Queue"
                    description={`${profiles.total} submission(s).`}
                    action={
                        <div className="flex items-center gap-2">
                            <label htmlFor="status-filter" className="sr-only">
                                Filter by status
                            </label>
                            <Select
                                id="status-filter"
                                className="w-52"
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        route('admin.verification.index'),
                                        event.target.value ? { status: event.target.value } : {},
                                        { preserveState: true, replace: true },
                                    )
                                }
                            >
                                <option value="">Awaiting review</option>
                                {statuses.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </Select>
                        </div>
                    }
                />

                {profiles.data.length === 0 ? (
                    <EmptyState
                        icon={ShieldCheck}
                        title="Nothing waiting"
                        description="No submissions match this filter."
                    />
                ) : (
                    <>
                        <Table caption="Verification submissions">
                            <thead>
                                <tr>
                                    <Th>Organization</Th>
                                    <Th>Legal name</Th>
                                    <Th>Tenant</Th>
                                    <Th>Status</Th>
                                    <Th>Waiting</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {profiles.data.map((row) => (
                                    <tr key={row.id}>
                                        <Td>
                                            <Link
                                                href={route('admin.verification.show', row.id)}
                                                className="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                {row.organization}
                                            </Link>
                                        </Td>
                                        <Td className="text-muted-foreground">{row.legalName}</Td>
                                        <Td className="text-muted-foreground">{row.tenant}</Td>
                                        <Td>
                                            <StatusBadge status={row.status} label={row.statusLabel} />
                                        </Td>
                                        <Td className="text-muted-foreground tabular-nums">
                                            {row.submittedAt === null
                                                ? '—'
                                                : `${row.waitingDays ?? 0} day(s)`}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>

                        {profiles.last_page > 1 ? (
                            <nav
                                aria-label="Pagination"
                                className="flex items-center justify-between gap-4 px-5 py-3"
                            >
                                <p className="text-sm text-muted-foreground">
                                    Showing {profiles.from ?? 0}–{profiles.to ?? 0} of {profiles.total}
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    {profiles.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? 'secondary' : 'ghost'}
                                            size="sm"
                                            disabled={link.url === null}
                                            onClick={() => link.url && router.get(link.url)}
                                            aria-current={link.active ? 'page' : undefined}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </nav>
                        ) : null}
                    </>
                )}
            </Card>
        </AdminLayout>
    );
}

function Count({ label, value }: { label: string; value: number }) {
    return (
        <Card>
            <CardBody className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
                <p className="text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
            </CardBody>
        </Card>
    );
}
