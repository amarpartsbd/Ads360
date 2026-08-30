import { router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface AuditRow {
    id: string;
    action: string;
    actor: string;
    actorType: string;
    tenant: string | null;
    organization: string | null;
    resourceType: string | null;
    resourceId: string | null;
    ipAddress: string | null;
    requestId: string | null;
    at: string | null;
}

export default function AuditLogsIndex({ logs }: { logs: Paginated<AuditRow> }) {
    return (
        <AdminLayout
            title="Audit logs"
            description="An append-only record of security and business events. Entries cannot be edited or removed."
        >
            <Card>
                <CardHeader title="Events" description={`${logs.total} recorded.`} />

                {logs.data.length === 0 ? (
                    <EmptyState icon={ScrollText} title="No audit events recorded yet" />
                ) : (
                    <>
                        <Table caption="Recorded audit events">
                            <thead>
                                <tr>
                                    <Th>Action</Th>
                                    <Th>Actor</Th>
                                    <Th>Tenant</Th>
                                    <Th>Resource</Th>
                                    <Th>IP</Th>
                                    <Th>When</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <Td className="font-mono text-xs">{log.action}</Td>
                                        <Td>
                                            {log.actor}
                                            {log.actorType !== 'USER' ? (
                                                <Badge className="ml-2">{log.actorType}</Badge>
                                            ) : null}
                                        </Td>
                                        <Td className="text-muted-foreground">
                                            {log.tenant ?? '—'}
                                            {log.organization ? (
                                                <span className="block text-xs">{log.organization}</span>
                                            ) : null}
                                        </Td>
                                        <Td className="text-muted-foreground">
                                            {log.resourceType
                                                ? `${log.resourceType} #${log.resourceId}`
                                                : '—'}
                                        </Td>
                                        <Td className="text-muted-foreground">{log.ipAddress ?? '—'}</Td>
                                        <Td className="text-muted-foreground">
                                            {log.at ? new Date(log.at).toLocaleString() : '—'}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>

                        {logs.last_page > 1 ? (
                            <nav
                                aria-label="Pagination"
                                className="flex items-center justify-between gap-4 px-5 py-3"
                            >
                                <p className="text-sm text-muted-foreground">
                                    Showing {logs.from ?? 0}–{logs.to ?? 0} of {logs.total}
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    {logs.links.map((link, index) => (
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
