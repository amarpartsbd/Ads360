import { Link, router } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Badge } from '@/Components/UI/Badge';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface ReviewRow {
    public_id: string;
    name: string;
    client: string | null;
    provider: string;
    status: string;
    statusLabel: string;
    currency: string;
    budget: string;
    chargedTotal: string;
    submittedAt: string | null;
    needsSecondApprover: boolean;
}

export default function AdminCampaignsIndex({
    campaigns,
    filters,
    statuses,
}: {
    campaigns: Paginated<ReviewRow>;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
}) {
    return (
        <AdminLayout
            title="Campaign review"
            description="Approving a campaign holds the client's budget and commits an ad account to it."
        >
            <Card>
                <CardHeader
                    title="Queue"
                    description="Oldest submissions first."
                    action={
                        <Select
                            aria-label="Filter by status"
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                router.get(
                                    route('admin.campaigns.index'),
                                    { status: event.target.value || undefined },
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            <option value="">Waiting for review</option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </Select>
                    }
                />

                {campaigns.data.length === 0 ? (
                    <EmptyState
                        icon={Megaphone}
                        title="Nothing waiting"
                        description="Campaigns appear here as clients submit them."
                    />
                ) : (
                    <Table caption="Campaigns awaiting review">
                        <thead>
                            <tr>
                                <Th>Campaign</Th>
                                <Th>Client</Th>
                                <Th>Status</Th>
                                <Th className="text-right">Budget</Th>
                                <Th className="text-right">Total charge</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {campaigns.data.map((campaign) => (
                                <tr key={campaign.public_id}>
                                    <Td>
                                        <Link
                                            href={route('admin.campaigns.show', campaign.public_id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {campaign.name}
                                        </Link>
                                        {campaign.needsSecondApprover ? (
                                            <p className="mt-1">
                                                <Badge tone="warning">Needs two approvers</Badge>
                                            </p>
                                        ) : null}
                                    </Td>
                                    <Td>{campaign.client ?? '—'}</Td>
                                    <Td>
                                        <StatusBadge status={campaign.status} label={campaign.statusLabel} />
                                    </Td>
                                    <Td className="text-right tabular-nums">{campaign.budget}</Td>
                                    <Td className="text-right tabular-nums">{campaign.chargedTotal}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </AdminLayout>
    );
}
