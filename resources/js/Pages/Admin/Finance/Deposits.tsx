import { router, useForm } from '@inertiajs/react';
import { Banknote, ExternalLink } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import { Textarea } from '@/Components/UI/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface DepositRow {
    id: string;
    reference: string;
    organization: string;
    tenant: string;
    method: string;
    methodLabel: string;
    amount: string;
    status: string;
    statusLabel: string;
    externalReference: string | null;
    hasProof: boolean;
    submittedAt: string | null;
    waitingDays: number | null;
    proofUrl: string | null;
}

export default function Deposits({
    payments,
    filters,
    statuses,
    counts,
}: {
    payments: Paginated<DepositRow>;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
    counts: { awaiting: number; verifiedToday: number };
}) {
    const [deciding, setDeciding] = useState<DepositRow | null>(null);

    return (
        <AdminLayout
            title="Deposits"
            description="Confirm client transfers before they are credited to a wallet."
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Count label="Awaiting verification" value={counts.awaiting} />
                <Count label="Verified today" value={counts.verifiedToday} />
            </div>

            <Card>
                <CardHeader
                    title="Queue"
                    description={`${payments.total} deposit(s).`}
                    action={
                        <>
                            <label htmlFor="status-filter" className="sr-only">
                                Filter by status
                            </label>
                            <Select
                                id="status-filter"
                                className="w-52"
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        route('admin.finance.deposits.index'),
                                        event.target.value ? { status: event.target.value } : {},
                                        { preserveState: true, replace: true },
                                    )
                                }
                            >
                                <option value="">Awaiting verification</option>
                                {statuses.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </Select>
                        </>
                    }
                />

                {payments.data.length === 0 ? (
                    <EmptyState icon={Banknote} title="Nothing waiting" />
                ) : (
                    <Table caption="Deposits awaiting verification">
                        <thead>
                            <tr>
                                <Th>Reference</Th>
                                <Th>Client</Th>
                                <Th>Method</Th>
                                <Th className="text-right">Amount</Th>
                                <Th>Client reference</Th>
                                <Th>Waiting</Th>
                                <Th className="text-right">Action</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.data.map((payment) => (
                                <tr key={payment.id}>
                                    <Td className="font-mono text-xs">{payment.reference}</Td>
                                    <Td>
                                        <span className="font-medium">{payment.organization}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {payment.tenant}
                                        </span>
                                    </Td>
                                    <Td>{payment.methodLabel}</Td>
                                    <Td className="text-right font-medium tabular-nums">{payment.amount}</Td>
                                    <Td>
                                        <span className="font-mono text-xs">
                                            {payment.externalReference ?? '—'}
                                        </span>
                                        {payment.proofUrl ? (
                                            <a
                                                href={payment.proofUrl}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="ml-2 inline-flex items-center gap-1 text-xs text-primary underline-offset-4 hover:underline"
                                            >
                                                Proof
                                                <ExternalLink className="size-3" aria-hidden="true" />
                                            </a>
                                        ) : null}
                                    </Td>
                                    <Td className="text-muted-foreground tabular-nums">
                                        {payment.waitingDays === null ? '—' : `${payment.waitingDays} day(s)`}
                                    </Td>
                                    <Td className="text-right">
                                        {payment.status === 'AWAITING_VERIFICATION' ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setDeciding(payment)}
                                            >
                                                Review
                                            </Button>
                                        ) : (
                                            <StatusBadge
                                                status={
                                                    payment.status === 'VERIFIED' ? 'ACTIVE' : payment.status
                                                }
                                                label={payment.statusLabel}
                                            />
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            {deciding ? <DecisionPanel payment={deciding} onClose={() => setDeciding(null)} /> : null}
        </AdminLayout>
    );
}

function DecisionPanel({ payment, onClose }: { payment: DepositRow; onClose: () => void }) {
    const verifyForm = useForm({ note: '' });
    const rejectForm = useForm({ reason: '' });

    const verify = (event: FormEvent) => {
        event.preventDefault();

        // Crediting a wallet is irreversible without a refund, so it is
        // confirmed explicitly (spec §72).
        if (
            !window.confirm(
                `Credit ${payment.amount} to ${payment.organization}? This adds real balance to their wallet.`,
            )
        ) {
            return;
        }

        verifyForm.post(route('admin.finance.deposits.verify', payment.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const reject = (event: FormEvent) => {
        event.preventDefault();
        rejectForm.post(route('admin.finance.deposits.reject', payment.id), {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Card>
            <CardHeader
                title={`Review ${payment.reference}`}
                description={`${payment.organization} · ${payment.amount} via ${payment.methodLabel}`}
                action={
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Close
                    </Button>
                }
            />
            <CardBody className="grid gap-6 lg:grid-cols-2">
                <form onSubmit={verify} className="space-y-3">
                    <Field
                        label="Verification note"
                        error={verifyForm.errors.note}
                        hint="Recorded on the payment for audit."
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={3}
                                value={verifyForm.data.note}
                                onChange={(event) => verifyForm.setData('note', event.target.value)}
                            />
                        )}
                    </Field>
                    <Button type="submit" loading={verifyForm.processing} className="w-full">
                        Confirm and credit {payment.amount}
                    </Button>
                </form>

                <form onSubmit={reject} className="space-y-3">
                    <Field
                        label="Reason for rejection"
                        error={rejectForm.errors.reason}
                        required
                        hint="The client will see this."
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={3}
                                value={rejectForm.data.reason}
                                onChange={(event) => rejectForm.setData('reason', event.target.value)}
                            />
                        )}
                    </Field>
                    <Button type="submit" variant="danger" loading={rejectForm.processing} className="w-full">
                        Reject deposit
                    </Button>
                </form>
            </CardBody>
        </Card>
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
