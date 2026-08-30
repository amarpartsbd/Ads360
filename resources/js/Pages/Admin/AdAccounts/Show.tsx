import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import AdminLayout from '@/Layouts/AdminLayout';
import type { AdAccountRow } from '@/Pages/Admin/AdAccounts/Index';

interface AdAccountDetail extends AdAccountRow {
    timezone: string;
    lastError: string | null;
    consecutiveFailures: number;
    disabledReason: string | null;
    riskScore: number;
    allocationPriority: number;
    monthlySpendLimit: string | null;
    currentMonthlySpend: string;
    committedAmount: string;
    pools: { id: string; name: string; status: string }[];
    allowedTransitions: { value: string; label: string }[];
}

export default function AdAccountShow({
    account,
    can,
}: {
    account: AdAccountDetail;
    can: { update: boolean; manageHealth: boolean };
}) {
    return (
        <AdminLayout
            title={account.name}
            description={`${account.providerLabel} · ${account.external_account_id}`}
            actions={
                <div className="flex gap-2">
                    <Button asChild variant="ghost">
                        <Link href={route('admin.ad-accounts.index')}>
                            <ArrowLeft aria-hidden="true" />
                            Back
                        </Link>
                    </Button>
                    {can.manageHealth ? (
                        <Button
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    route('admin.ad-accounts.refresh', account.public_id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw aria-hidden="true" />
                            Check with provider
                        </Button>
                    ) : null}
                </div>
            }
        >
            {account.needsAttention ? (
                <Alert tone="warning" title="This account needs attention">
                    {account.lastError ??
                        'Allocation will not place new campaigns here until it returns to health.'}
                </Alert>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="State" />
                    <CardBody className="space-y-3 text-sm">
                        <Row label="Status">
                            <StatusBadge status={account.status} label={account.statusLabel} />
                        </Row>
                        <Row label="Health">
                            <StatusBadge status={account.health_status} label={account.healthLabel} />
                        </Row>
                        <Row label="Billing">
                            <StatusBadge status={account.billing_status} label={account.billingLabel} />
                        </Row>
                        <Row label="Allocatable">{account.allocatable ? 'Yes' : 'No'}</Row>
                        <Row label="Failed checks">{account.consecutiveFailures}</Row>
                        <Row label="Last checked">
                            {account.lastSyncedAt ? new Date(account.lastSyncedAt).toLocaleString() : 'Never'}
                        </Row>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Spend" description="As last reported by the provider." />
                    <CardBody className="space-y-3 text-sm">
                        <Row label="Today">{account.currentDailySpend}</Row>
                        <Row label="Daily limit">{account.dailySpendLimit ?? 'No limit set'}</Row>
                        <Row label="This month">{account.currentMonthlySpend}</Row>
                        <Row label="Monthly limit">{account.monthlySpendLimit ?? 'No limit set'}</Row>
                        <Row label="Committed">{account.committedAmount}</Row>
                        <Row label="Headroom today">{account.dailyHeadroom ?? 'Not constrained'}</Row>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Pools" description="Where allocation may draw this account from." />
                    <CardBody className="space-y-2 text-sm">
                        {account.pools.length === 0 ? (
                            <p className="text-muted-foreground">
                                This account is in no pool, so allocation will never reach it.
                            </p>
                        ) : (
                            account.pools.map((pool) => (
                                <div key={pool.id} className="flex items-center justify-between gap-2">
                                    <Link
                                        href={route('admin.ad-account-pools.show', pool.id)}
                                        className="text-primary underline-offset-4 hover:underline"
                                    >
                                        {pool.name}
                                    </Link>
                                    <StatusBadge status={pool.status} />
                                </div>
                            ))
                        )}
                    </CardBody>
                </Card>
            </div>

            {can.update ? <SettingsForm account={account} /> : null}
            {can.update && account.allowedTransitions.length > 0 ? <StatusForm account={account} /> : null}
        </AdminLayout>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium tabular-nums">{children}</span>
        </div>
    );
}

function SettingsForm({ account }: { account: AdAccountDetail }) {
    const { data, setData, put, processing, errors } = useForm({
        name: account.name,
        timezone: account.timezone,
        risk_score: account.riskScore,
        allocation_priority: account.allocationPriority,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('admin.ad-accounts.update', account.public_id), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader
                title="Settings"
                description="Spend figures are not editable: they mirror what the provider reports."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Name" error={errors.name} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Time zone" error={errors.timezone} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.timezone}
                                onChange={(event) => setData('timezone', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Risk score"
                        hint="0 is lowest risk, 100 is highest. Pools can refuse accounts above a threshold."
                        error={errors.risk_score}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                type="number"
                                min={0}
                                max={100}
                                value={data.risk_score}
                                onChange={(event) => setData('risk_score', Number(event.target.value))}
                            />
                        )}
                    </Field>

                    <Field
                        label="Allocation priority"
                        hint="Higher values are preferred when a pool ranks by priority."
                        error={errors.allocation_priority}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                type="number"
                                min={0}
                                max={100}
                                value={data.allocation_priority}
                                onChange={(event) =>
                                    setData('allocation_priority', Number(event.target.value))
                                }
                            />
                        )}
                    </Field>

                    <div className="sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Save settings
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}

function StatusForm({ account }: { account: AdAccountDetail }) {
    const { data, setData, post, processing, errors } = useForm({
        status: account.allowedTransitions[0]?.value ?? '',
        reason: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.ad-accounts.status', account.public_id), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader
                title="Change status"
                description="Retiring an account is final: its spend history stays, but it never returns to service."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="New status" error={errors.status} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.status}
                                onChange={(event) => setData('status', event.target.value)}
                            >
                                {account.allowedTransitions.map((transition) => (
                                    <option key={transition.value} value={transition.value}>
                                        {transition.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="Reason" hint="Recorded in the audit log." error={errors.reason}>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.reason}
                                onChange={(event) => setData('reason', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="sm:col-span-2">
                        <Button type="submit" variant="secondary" loading={processing}>
                            Change status
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
