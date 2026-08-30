import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';
import type { PoolRow } from '@/Pages/Admin/AdAccounts/Pools/Index';
import { Layers } from 'lucide-react';

interface Member {
    id: string;
    name: string;
    status: string;
    health: string;
    weight: number;
    utilisation: number | null;
    blockedBy: string[];
}

interface AllocationRules {
    required_verification_status: string;
    minimum_wallet_balance_minor: number | null;
    allowed_countries: string[] | null;
    allowed_categories: string[] | null;
    blocked_categories: string[];
    max_account_risk_score: number | null;
    max_daily_utilisation_percent: number | null;
    reserve_headroom_minor: number;
    require_healthy_account: boolean;
    max_clients_per_account: number | null;
}

interface PoolDetail extends PoolRow {
    description: string | null;
    rules: AllocationRules;
    members: Member[];
    allowedTransitions: { value: string; label: string }[];
}

export default function PoolShow({
    pool,
    candidates,
    can,
}: {
    pool: PoolDetail;
    candidates: { id: string; name: string; health: string }[];
    can: { update: boolean; manageMembers: boolean };
}) {
    const usable = pool.members.filter((member) => member.blockedBy.length === 0);

    return (
        <AdminLayout
            title={pool.name}
            description={`${pool.providerLabel} · ${pool.currency} · ${pool.strategyLabel}`}
            actions={
                <Button asChild variant="ghost">
                    <Link href={route('admin.ad-account-pools.index')}>
                        <ArrowLeft aria-hidden="true" />
                        Back
                    </Link>
                </Button>
            }
        >
            {pool.allocatable && usable.length === 0 ? (
                <Alert tone="warning" title="This pool is active but has nothing to hand out">
                    Every account in it is currently blocked. The reasons are listed against each account
                    below.
                </Alert>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="Pool" />
                    <CardBody className="space-y-3 text-sm">
                        <Row label="Status">
                            <StatusBadge status={pool.status} label={pool.statusLabel} />
                        </Row>
                        <Row label="Strategy">{pool.strategyLabel}</Row>
                        <Row label="Priority">{pool.priority}</Row>
                        <Row label="Accounts">{pool.members.length}</Row>
                        <Row label="Available now">{usable.length}</Row>
                        {pool.description ? (
                            <p className="text-muted-foreground">{pool.description}</p>
                        ) : null}
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader
                        title="Allocation rules"
                        description="Both halves must pass: the client's own standing, and the account being considered."
                    />
                    <CardBody className="space-y-3 text-sm">
                        <Row label="Client must be">{pool.rules.required_verification_status}</Row>
                        <Row label="Minimum balance">
                            {pool.rules.minimum_wallet_balance_minor === null
                                ? 'No minimum'
                                : `${pool.rules.minimum_wallet_balance_minor} minor units`}
                        </Row>
                        <Row label="Countries">{pool.rules.allowed_countries?.join(', ') ?? 'Any'}</Row>
                        <Row label="Categories">{pool.rules.allowed_categories?.join(', ') ?? 'Any'}</Row>
                        <Row label="Blocked categories">
                            {pool.rules.blocked_categories.length > 0
                                ? pool.rules.blocked_categories.join(', ')
                                : 'None'}
                        </Row>
                        <Row label="Max account risk">
                            {pool.rules.max_account_risk_score ?? 'Not limited'}
                        </Row>
                        <Row label="Max utilisation">
                            {pool.rules.max_daily_utilisation_percent === null
                                ? 'Not limited'
                                : `${pool.rules.max_daily_utilisation_percent}%`}
                        </Row>
                        <Row label="Healthy accounts only">
                            {pool.rules.require_healthy_account ? 'Yes' : 'No'}
                        </Row>
                    </CardBody>
                </Card>
            </div>

            <Card>
                <CardHeader
                    title="Accounts in this pool"
                    description="An account listed as blocked will be skipped by allocation until the reason clears."
                />

                {pool.members.length === 0 ? (
                    <EmptyState
                        icon={Layers}
                        title="No accounts yet"
                        description="Add accounts of the same provider and currency below."
                    />
                ) : (
                    <Table caption="Pool members">
                        <thead>
                            <tr>
                                <Th>Account</Th>
                                <Th>Status</Th>
                                <Th>Health</Th>
                                <Th className="text-right">Weight</Th>
                                <Th className="text-right">Utilisation</Th>
                                <Th>Blocked by</Th>
                                {can.manageMembers ? <Th className="text-right">Remove</Th> : null}
                            </tr>
                        </thead>
                        <tbody>
                            {pool.members.map((member) => (
                                <tr key={member.id}>
                                    <Td>
                                        <Link
                                            href={route('admin.ad-accounts.show', member.id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {member.name}
                                        </Link>
                                    </Td>
                                    <Td>{member.status}</Td>
                                    <Td>{member.health}</Td>
                                    <Td className="text-right tabular-nums">{member.weight}</Td>
                                    <Td className="text-right tabular-nums">
                                        {member.utilisation === null ? '—' : `${member.utilisation}%`}
                                    </Td>
                                    <Td>
                                        {member.blockedBy.length === 0 ? (
                                            <span className="text-muted-foreground">Nothing</span>
                                        ) : (
                                            <ul className="space-y-0.5 text-xs text-muted-foreground">
                                                {member.blockedBy.map((reason) => (
                                                    <li key={reason}>{reason}</li>
                                                ))}
                                            </ul>
                                        )}
                                    </Td>
                                    {can.manageMembers ? (
                                        <Td className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        route('admin.ad-account-pools.members.destroy', {
                                                            adAccountPool: pool.id,
                                                            adAccount: member.id,
                                                        }),
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <Trash2 aria-hidden="true" />
                                                Remove
                                            </Button>
                                        </Td>
                                    ) : null}
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            {can.manageMembers ? <AddMemberForm pool={pool} candidates={candidates} /> : null}
            {can.update && pool.allowedTransitions.length > 0 ? <StatusForm pool={pool} /> : null}
        </AdminLayout>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{children}</span>
        </div>
    );
}

function AddMemberForm({
    pool,
    candidates,
}: {
    pool: PoolDetail;
    candidates: { id: string; name: string; health: string }[];
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        account: candidates[0]?.id ?? '',
        weight: 1,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('admin.ad-account-pools.members.store', pool.id), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card>
            <CardHeader
                title="Add an account"
                description={`Only ${pool.providerLabel} accounts in ${pool.currency} can join this pool.`}
            />
            <CardBody>
                {candidates.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Every matching account is already in this pool.
                    </p>
                ) : (
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                        <Field label="Account" error={errors.account} required className="sm:col-span-2">
                            {(field) => (
                                <Select
                                    {...field}
                                    value={data.account}
                                    onChange={(event) => setData('account', event.target.value)}
                                >
                                    {candidates.map((candidate) => (
                                        <option key={candidate.id} value={candidate.id}>
                                            {candidate.name} — {candidate.health}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>

                        <Field
                            label="Weight"
                            hint="Only used by the weighted strategy."
                            error={errors.weight}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="number"
                                    min={1}
                                    value={data.weight}
                                    onChange={(event) => setData('weight', Number(event.target.value))}
                                />
                            )}
                        </Field>

                        <div className="sm:col-span-3">
                            <Button type="submit" loading={processing}>
                                Add to pool
                            </Button>
                        </div>
                    </form>
                )}
            </CardBody>
        </Card>
    );
}

function StatusForm({ pool }: { pool: PoolDetail }) {
    const { data, setData, post, processing, errors } = useForm({
        status: pool.allowedTransitions[0]?.value ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.ad-account-pools.status', pool.id), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader
                title="Change status"
                description="Archiving is final: an archived pool cannot be changed or reopened."
            />
            <CardBody>
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <Field label="New status" error={errors.status} className="min-w-56">
                        {(field) => (
                            <Select
                                {...field}
                                value={data.status}
                                onChange={(event) => setData('status', event.target.value)}
                            >
                                {pool.allowedTransitions.map((transition) => (
                                    <option key={transition.value} value={transition.value}>
                                        {transition.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Button type="submit" variant="secondary" loading={processing}>
                        Change status
                    </Button>
                </form>
            </CardBody>
        </Card>
    );
}
