import { router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';
import type { Paginated } from '@/Types';

interface InvoiceRow {
    id: string;
    number: string;
    kind: string;
    kindLabel: string;
    status: string;
    statusLabel: string;
    total: string;
    outstanding: string;
    issuedOn: string | null;
    dueOn: string | null;
}

export default function Invoices({ invoices }: { invoices: Paginated<InvoiceRow> }) {
    return (
        <ClientLayout title="Invoices" description="Invoices and credit notes issued to your account.">
            <Card>
                <CardHeader title="Documents" description={`${invoices.total} issued.`} />

                {invoices.data.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title="No invoices yet"
                        description="Invoices appear here once fees have been charged."
                    />
                ) : (
                    <>
                        <Table caption="Invoices and credit notes">
                            <thead>
                                <tr>
                                    <Th>Number</Th>
                                    <Th>Type</Th>
                                    <Th className="text-right">Total</Th>
                                    <Th className="text-right">Outstanding</Th>
                                    <Th>Status</Th>
                                    <Th>Issued</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.map((invoice) => (
                                    <tr key={invoice.id}>
                                        <Td className="font-mono text-xs font-medium">{invoice.number}</Td>
                                        <Td>{invoice.kindLabel}</Td>
                                        <Td className="text-right tabular-nums">{invoice.total}</Td>
                                        <Td className="text-right text-muted-foreground tabular-nums">
                                            {invoice.outstanding}
                                        </Td>
                                        <Td>
                                            <StatusBadge
                                                status={
                                                    invoice.status === 'VOID' ? 'REVOKED' : invoice.status
                                                }
                                                label={invoice.statusLabel}
                                            />
                                        </Td>
                                        <Td className="text-muted-foreground">
                                            {invoice.issuedOn
                                                ? new Date(invoice.issuedOn).toLocaleDateString()
                                                : '—'}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>

                        {invoices.last_page > 1 ? (
                            <nav
                                aria-label="Pagination"
                                className="flex items-center justify-between gap-4 px-5 py-3"
                            >
                                <p className="text-sm text-muted-foreground">
                                    Showing {invoices.from ?? 0}–{invoices.to ?? 0} of {invoices.total}
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    {invoices.links.map((link, index) => (
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
        </ClientLayout>
    );
}
