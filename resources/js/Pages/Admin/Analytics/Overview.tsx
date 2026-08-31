import { Link } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { StatTile } from '@/Components/Analytics/StatTile';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';

interface ClientRow {
    id: string;
    name: string;
    spend: string;
    impressions: number;
    clicks: number;
    conversions: number;
    clickThroughRate: string | null;
    costPerClick: string | null;
}

export default function AnalyticsOverview({
    clients,
    period,
    summary,
}: {
    clients: ClientRow[];
    period: { from: string; to: string };
    summary: { openDiscrepancies: number; campaignsChecked: number; liveCampaigns: number };
}) {
    return (
        <AdminLayout
            title="Analytics"
            description={`Platform-wide performance, ${period.from} to ${period.to}.`}
            actions={
                <Button asChild variant="secondary">
                    <Link href={route('admin.analytics.reconciliation')}>Reconciliation queue</Link>
                </Button>
            }
        >
            <div className="grid gap-4 sm:grid-cols-3">
                <StatTile label="Live campaigns" value={summary.liveCampaigns.toLocaleString()} />
                <StatTile label="Campaigns checked" value={summary.campaignsChecked.toLocaleString()} />
                <StatTile
                    label="Open discrepancies"
                    value={summary.openDiscrepancies.toLocaleString()}
                    hint={summary.openDiscrepancies > 0 ? 'Needs a look' : 'Everything agrees'}
                />
            </div>

            <Card>
                <CardHeader title="By client" description="Last 30 days, clients with activity only." />

                {clients.length === 0 ? (
                    <EmptyState
                        icon={BarChart3}
                        title="No activity in this period"
                        description="Figures appear here once campaigns are running."
                    />
                ) : (
                    <Table caption="Performance by client">
                        <thead>
                            <tr>
                                <Th>Client</Th>
                                <Th className="text-right">Spend</Th>
                                <Th className="text-right">Impressions</Th>
                                <Th className="text-right">Clicks</Th>
                                <Th className="text-right">CTR</Th>
                                <Th className="text-right">Conversions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {clients.map((client) => (
                                <tr key={client.id}>
                                    <Td className="font-medium">{client.name}</Td>
                                    <Td className="text-right tabular-nums">{client.spend}</Td>
                                    <Td className="text-right tabular-nums">
                                        {client.impressions.toLocaleString()}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {client.clicks.toLocaleString()}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {client.clickThroughRate ? `${client.clickThroughRate}%` : '—'}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {client.conversions.toLocaleString()}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </AdminLayout>
    );
}
