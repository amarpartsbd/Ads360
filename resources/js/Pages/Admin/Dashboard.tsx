import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { ScrollText } from 'lucide-react';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';

interface AuditEvent {
    id: string;
    action: string;
    actor: string;
    at: string | null;
}

export default function AdminDashboard({
    metrics,
    recentAuditEvents,
}: {
    metrics: {
        tenants: number | null;
        organizations: number | null;
        pendingVerification: number | null;
        platformUsers: number;
    };
    recentAuditEvents: AuditEvent[];
}) {
    return (
        <AdminLayout title="Platform overview" description="Current state of the platform.">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric label="Tenants" value={metrics.tenants} />
                <Metric label="Organizations" value={metrics.organizations} />
                <Metric label="Awaiting verification" value={metrics.pendingVerification} />
                <Metric label="Platform staff" value={metrics.platformUsers} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Metric label="Platform spend" value={null} note="Finance module" />
                <Metric label="Wallet liability" value={null} note="Finance module" />
                <Metric label="Active campaigns" value={null} note="Campaign module" />
                <Metric label="Account health alerts" value={null} note="Ad infrastructure module" />
            </div>

            <Card>
                <CardHeader title="Recent audit events" description="The most recent recorded actions." />
                {recentAuditEvents.length === 0 ? (
                    <EmptyState icon={ScrollText} title="No audit events recorded yet" />
                ) : (
                    <Table caption="Recent audit events">
                        <thead>
                            <tr>
                                <Th>Action</Th>
                                <Th>Actor</Th>
                                <Th>When</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {recentAuditEvents.map((event) => (
                                <tr key={event.id}>
                                    <Td className="font-mono text-xs">{event.action}</Td>
                                    <Td>{event.actor}</Td>
                                    <Td className="text-muted-foreground">
                                        {event.at ? new Date(event.at).toLocaleString() : '—'}
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

/**
 * A null value means the figure is not available yet — either the viewer lacks
 * the permission, or the module that produces it has not shipped. It is never
 * rendered as a zero, which would read as a real measurement.
 */
function Metric({ label, value, note }: { label: string; value: number | null; note?: string }) {
    return (
        <Card>
            <CardBody className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
                <p className="text-2xl font-semibold tracking-tight tabular-nums">
                    {value === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : (
                        value.toLocaleString()
                    )}
                </p>
                {note ? <p className="text-xs text-muted-foreground">Awaiting {note}</p> : null}
            </CardBody>
        </Card>
    );
}
