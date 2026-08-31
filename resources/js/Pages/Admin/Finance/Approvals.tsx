import { router, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Textarea } from '@/Components/UI/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface Decision {
    approver: string;
    decision: string;
    note: string | null;
    at: string | null;
}

interface ApprovalRow {
    id: string;
    action: string;
    actionLabel: string;
    summary: string;
    amount: string | null;
    organization: string | null;
    status: string;
    statusLabel: string;
    requestedBy: string;
    reason: string | null;
    required: number;
    received: number;
    /** What is still missing, in one sentence. Null once nothing is. */
    outstanding: string | null;
    needsSenior: boolean;
    /** Why this needs more scrutiny than its size asks for, if anything does. */
    elevation: string | null;
    decisions: Decision[];
    canDecide: boolean;
    isOwnRequest: boolean;
    createdAt: string | null;
}

export default function Approvals({
    requests,
    filters,
    statuses,
}: {
    requests: Paginated<ApprovalRow>;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
}) {
    const [deciding, setDeciding] = useState<string | null>(null);

    return (
        <AdminLayout title="Approvals" description="High-value actions waiting on a second pair of eyes.">
            <Card>
                <CardHeader
                    title="Queue"
                    description={`${requests.total} request(s).`}
                    action={
                        <>
                            <label htmlFor="approval-status" className="sr-only">
                                Filter by status
                            </label>
                            <Select
                                id="approval-status"
                                className="w-52"
                                value={filters.status ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        route('admin.finance.approvals.index'),
                                        event.target.value ? { status: event.target.value } : {},
                                        { preserveState: true, replace: true },
                                    )
                                }
                            >
                                <option value="">Open requests</option>
                                {statuses.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </Select>
                        </>
                    }
                />

                {requests.data.length === 0 ? (
                    <EmptyState icon={ShieldCheck} title="Nothing awaiting approval" />
                ) : (
                    <CardBody className="space-y-4">
                        {requests.data.map((request) => (
                            <div
                                key={request.id}
                                className="rounded-[var(--radius-card)] border border-border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge>{request.actionLabel}</Badge>
                                            <StatusBadge
                                                status={
                                                    request.status === 'PENDING'
                                                        ? 'PENDING'
                                                        : request.status === 'APPROVED' ||
                                                            request.status === 'EXECUTED'
                                                          ? 'ACTIVE'
                                                          : 'REVOKED'
                                                }
                                                label={request.statusLabel}
                                            />
                                            <span className="text-xs text-muted-foreground">
                                                {request.received} of {request.required} approvals
                                            </span>
                                            {request.needsSenior ? (
                                                <Badge tone="warning">Senior approval needed</Badge>
                                            ) : null}
                                        </div>
                                        <p className="text-sm font-medium">{request.summary}</p>
                                        <p className="text-xs text-muted-foreground">
                                            Requested by {request.requestedBy}
                                            {request.organization ? ` · ${request.organization}` : ''}
                                            {request.createdAt
                                                ? ` · ${new Date(request.createdAt).toLocaleString()}`
                                                : ''}
                                        </p>
                                        {request.reason ? (
                                            <p className="text-sm text-muted-foreground">{request.reason}</p>
                                        ) : null}
                                        {request.elevation ? (
                                            <p className="text-sm text-warning-foreground">
                                                {request.elevation}
                                            </p>
                                        ) : null}
                                        {request.outstanding ? (
                                            <p className="text-xs text-muted-foreground">
                                                {request.outstanding}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="text-right">
                                        {request.amount ? (
                                            <p className="text-lg font-semibold tabular-nums">
                                                {request.amount}
                                            </p>
                                        ) : null}
                                        {request.canDecide ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="mt-2"
                                                onClick={() =>
                                                    setDeciding(deciding === request.id ? null : request.id)
                                                }
                                            >
                                                {deciding === request.id ? 'Cancel' : 'Decide'}
                                            </Button>
                                        ) : request.isOwnRequest ? (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                You raised this — someone else must approve it.
                                            </p>
                                        ) : null}
                                    </div>
                                </div>

                                {request.decisions.length > 0 ? (
                                    <ul className="mt-3 space-y-1 border-t border-border pt-3">
                                        {request.decisions.map((decision, index) => (
                                            <li key={index} className="text-xs text-muted-foreground">
                                                <span className="font-medium text-foreground">
                                                    {decision.approver}
                                                </span>{' '}
                                                {decision.decision === 'APPROVE' ? 'approved' : 'rejected'}
                                                {decision.at
                                                    ? ` on ${new Date(decision.at).toLocaleString()}`
                                                    : ''}
                                                {decision.note ? ` — ${decision.note}` : ''}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}

                                {deciding === request.id ? (
                                    <DecisionForms request={request} onDone={() => setDeciding(null)} />
                                ) : null}
                            </div>
                        ))}
                    </CardBody>
                )}
            </Card>
        </AdminLayout>
    );
}

function DecisionForms({ request, onDone }: { request: ApprovalRow; onDone: () => void }) {
    const approveForm = useForm({ note: '' });
    const rejectForm = useForm({ reason: '' });

    const approve = (event: FormEvent) => {
        event.preventDefault();

        if (!window.confirm(`Approve: ${request.summary}? This will execute once fully approved.`)) {
            return;
        }

        approveForm.post(route('admin.finance.approvals.approve', request.id), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    const reject = (event: FormEvent) => {
        event.preventDefault();
        rejectForm.post(route('admin.finance.approvals.reject', request.id), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <div className="mt-4 grid gap-4 border-t border-border pt-4 lg:grid-cols-2">
            <form onSubmit={approve} className="space-y-3">
                <Field label="Approval note" error={approveForm.errors.note}>
                    {(props) => (
                        <Textarea
                            {...props}
                            rows={2}
                            value={approveForm.data.note}
                            onChange={(event) => approveForm.setData('note', event.target.value)}
                        />
                    )}
                </Field>
                <Button type="submit" loading={approveForm.processing} className="w-full">
                    Approve
                </Button>
            </form>

            <form onSubmit={reject} className="space-y-3">
                <Field label="Reason for refusal" error={rejectForm.errors.reason} required>
                    {(props) => (
                        <Textarea
                            {...props}
                            rows={2}
                            value={rejectForm.data.reason}
                            onChange={(event) => rejectForm.setData('reason', event.target.value)}
                        />
                    )}
                </Field>
                <Button type="submit" variant="danger" loading={rejectForm.processing} className="w-full">
                    Reject
                </Button>
            </form>
        </div>
    );
}
