import { Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Scale } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { StatTile } from '@/Components/Analytics/StatTile';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Select } from '@/Components/UI/Select';
import { Table, Td, Th } from '@/Components/UI/Table';
import { Textarea } from '@/Components/UI/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface ReconciliationRow {
    id: string;
    client: string | null;
    campaign: string | null;
    campaignId: string | null;
    period: string;
    status: string;
    statusLabel: string;
    underCharged: boolean;
    providerSpendFormatted: string;
    ledgerSpendFormatted: string;
    varianceFormatted: string;
    checkedAt: string | null;
    resolutionNote: string | null;
    can: { resolve: boolean };
}

/**
 * The reconciliation queue (spec §78).
 *
 * Settling a row records a decision about a discrepancy; it does not make the
 * numbers agree. If money genuinely has to move, that is a wallet adjustment
 * with its own approval — which is why the note is required here and the
 * button says what it does.
 */
export default function Reconciliation({
    reconciliations,
    filters,
    statuses,
    summary,
}: {
    reconciliations: Paginated<ReconciliationRow>;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
    summary: { openDiscrepancies: number; campaignsChecked: number; liveCampaigns: number };
}) {
    return (
        <AdminLayout
            title="Spend reconciliation"
            description="What the advertising platforms say was spent, against what the ledger charged."
        >
            <Alert tone="info" title="Settling a row does not move money">
                Recording a decision closes the investigation. If a client has been over- or under-charged,
                correct it with a wallet adjustment, which needs its own approval.
            </Alert>

            <div className="grid gap-4 sm:grid-cols-3">
                <StatTile label="Open discrepancies" value={summary.openDiscrepancies.toLocaleString()} />
                <StatTile label="Campaigns checked" value={summary.campaignsChecked.toLocaleString()} />
                <StatTile label="Live campaigns" value={summary.liveCampaigns.toLocaleString()} />
            </div>

            <Card>
                <CardHeader
                    title="Queue"
                    description="Largest difference first."
                    action={
                        <Select
                            aria-label="Filter by status"
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                router.get(
                                    route('admin.analytics.reconciliation'),
                                    { status: event.target.value || undefined },
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            <option value="">Needs investigation</option>
                            {statuses.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </Select>
                    }
                />

                {reconciliations.data.length === 0 ? (
                    <EmptyState
                        icon={CheckCircle2}
                        title="Nothing to investigate"
                        description="Every campaign checked so far agrees with the ledger."
                    />
                ) : (
                    <Table caption="Spend reconciliations">
                        <thead>
                            <tr>
                                <Th>Campaign</Th>
                                <Th>Period</Th>
                                <Th className="text-right">Platform says</Th>
                                <Th className="text-right">Ledger charged</Th>
                                <Th className="text-right">Difference</Th>
                                <Th className="text-right">Action</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {reconciliations.data.map((row) => (
                                <ReconciliationRowView key={row.id} row={row} />
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </AdminLayout>
    );
}

function ReconciliationRowView({ row }: { row: ReconciliationRow }) {
    const [settling, setSettling] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ note: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('admin.analytics.reconciliation.resolve', row.id), {
            preserveScroll: true,
            onSuccess: () => setSettling(false),
        });
    };

    return (
        <tr>
            <Td>
                {row.campaignId ? (
                    <Link
                        href={route('admin.campaigns.show', row.campaignId)}
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        {row.campaign}
                    </Link>
                ) : (
                    <span className="font-medium">{row.campaign ?? '—'}</span>
                )}
                <p className="text-xs text-muted-foreground">{row.client ?? '—'}</p>
                {row.resolutionNote ? (
                    <p className="mt-1 text-xs text-muted-foreground">{row.resolutionNote}</p>
                ) : null}
            </Td>
            <Td className="text-xs">{row.period}</Td>
            <Td className="text-right tabular-nums">{row.providerSpendFormatted}</Td>
            <Td className="text-right tabular-nums">{row.ledgerSpendFormatted}</Td>
            <Td className="text-right">
                <span className="tabular-nums">{row.varianceFormatted}</span>
                <p className="mt-1">
                    {/* The direction matters: one costs the platform, the other overcharges a client. */}
                    <Badge tone={row.underCharged ? 'warning' : 'neutral'}>
                        {row.underCharged ? 'Under-charged' : 'Over-charged'}
                    </Badge>
                </p>
            </Td>
            <Td className="text-right">
                {row.status === 'RESOLVED' ? (
                    <span className="text-xs text-muted-foreground">Settled</span>
                ) : row.can.resolve ? (
                    settling ? (
                        <form onSubmit={submit} className="space-y-2 text-left">
                            <Field label="Why is this settled?" error={errors.note} required>
                                {(field) => (
                                    <Textarea
                                        {...field}
                                        rows={2}
                                        value={data.note}
                                        onChange={(event) => setData('note', event.target.value)}
                                    />
                                )}
                            </Field>
                            <div className="flex gap-2">
                                <Button type="submit" size="sm" loading={processing}>
                                    Record
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setSettling(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <Button variant="ghost" size="sm" onClick={() => setSettling(true)}>
                            <Scale aria-hidden="true" />
                            Settle
                        </Button>
                    )
                ) : (
                    <span className="text-xs text-muted-foreground">No permission</span>
                )}
            </Td>
        </tr>
    );
}
