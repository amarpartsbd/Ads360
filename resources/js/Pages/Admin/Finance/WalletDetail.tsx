import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { LedgerTable, type LedgerRow } from '@/Components/Finance/LedgerTable';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Textarea } from '@/Components/UI/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface AdminLedgerRow extends LedgerRow {
    reservedAfter: string;
    author: string;
    group: string;
}

interface WalletDetailData {
    id: string;
    organization: string;
    tenant: string;
    currency: string;
    available: string;
    reserved: string;
    total: string;
    status: string;
    statusLabel: string;
    reconciled: boolean;
}

export default function WalletDetail({
    wallet,
    entries,
    can,
    thresholds,
}: {
    wallet: WalletDetailData;
    entries: Paginated<AdminLedgerRow>;
    can: { adjust: boolean; refund: boolean };
    thresholds: { adjustment: string; refund: string };
}) {
    return (
        <AdminLayout
            title={wallet.organization}
            description={`${wallet.currency} wallet · ${wallet.tenant}`}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href={route('admin.finance.wallets.index')}>Back to wallets</Link>
                </Button>
            }
        >
            {!wallet.reconciled ? (
                <Alert tone="danger" title="This wallet does not reconcile">
                    The cached balance disagrees with the sum of its ledger entries. Do not adjust it —
                    investigate the discrepancy first (spec §78).
                </Alert>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-4">
                <Summary label="Available" value={wallet.available} />
                <Summary label="Reserved" value={wallet.reserved} />
                <Summary label="Total" value={wallet.total} />
                <Card>
                    <CardBody className="space-y-1">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Status
                        </p>
                        <StatusBadge status={wallet.status} label={wallet.statusLabel} />
                    </CardBody>
                </Card>
            </div>

            {can.adjust || can.refund ? (
                <div className="grid gap-4 lg:grid-cols-2">
                    {can.adjust ? <AdjustForm wallet={wallet} threshold={thresholds.adjustment} /> : null}
                    {can.refund ? <RefundForm wallet={wallet} threshold={thresholds.refund} /> : null}
                </div>
            ) : null}

            <Card>
                <CardHeader
                    title="Ledger"
                    description={`${entries.total} entries. Append-only — corrections are reversals.`}
                />
                <LedgerTable entries={entries.data} />

                {entries.last_page > 1 ? (
                    <nav
                        aria-label="Pagination"
                        className="flex items-center justify-between gap-4 px-5 py-3"
                    >
                        <p className="text-sm text-muted-foreground">
                            Showing {entries.from ?? 0}–{entries.to ?? 0} of {entries.total}
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {entries.links.map((link, index) => (
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
            </Card>
        </AdminLayout>
    );
}

function AdjustForm({ wallet, threshold }: { wallet: WalletDetailData; threshold: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        amount: '',
        direction: 'credit',
        reason: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (
            !window.confirm(
                `${data.direction === 'credit' ? 'Add' : 'Remove'} ${data.amount} ${wallet.currency} ${
                    data.direction === 'credit' ? 'to' : 'from'
                } this wallet?`,
            )
        ) {
            return;
        }

        post(route('admin.finance.wallets.adjust', wallet.id), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card>
            <CardHeader
                title="Manual adjustment"
                description={`Adjustments of ${threshold} or more need a second approver.`}
            />
            <CardBody>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Direction" error={errors.direction} required>
                            {(props) => (
                                <Select
                                    {...props}
                                    value={data.direction}
                                    onChange={(event) => setData('direction', event.target.value)}
                                >
                                    <option value="credit">Add to balance</option>
                                    <option value="debit">Remove from balance</option>
                                </Select>
                            )}
                        </Field>

                        <Field label={`Amount (${wallet.currency})`} error={errors.amount} required>
                            {(props) => (
                                <Input
                                    {...props}
                                    inputMode="decimal"
                                    value={data.amount}
                                    onChange={(event) => setData('amount', event.target.value)}
                                />
                            )}
                        </Field>
                    </div>

                    <Field
                        label="Reason"
                        error={errors.reason}
                        required
                        hint="Recorded permanently on the ledger entry."
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={2}
                                value={data.reason}
                                onChange={(event) => setData('reason', event.target.value)}
                            />
                        )}
                    </Field>

                    <Button type="submit" loading={processing} className="w-full">
                        Post adjustment
                    </Button>
                </form>
            </CardBody>
        </Card>
    );
}

function RefundForm({ wallet, threshold }: { wallet: WalletDetailData; threshold: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({ amount: '', reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!window.confirm(`Refund ${data.amount} ${wallet.currency} from this wallet?`)) {
            return;
        }

        post(route('admin.finance.wallets.refund', wallet.id), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card>
            <CardHeader
                title="Issue a refund"
                description={`Refunds of ${threshold} or more need a second approver.`}
            />
            <CardBody>
                <form onSubmit={submit} className="space-y-4">
                    <Field label={`Amount (${wallet.currency})`} error={errors.amount} required>
                        {(props) => (
                            <Input
                                {...props}
                                inputMode="decimal"
                                value={data.amount}
                                onChange={(event) => setData('amount', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Reason" error={errors.reason} required>
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={2}
                                value={data.reason}
                                onChange={(event) => setData('reason', event.target.value)}
                            />
                        )}
                    </Field>

                    <Alert tone="warning">
                        <span className="inline-flex items-center gap-1">
                            <AlertTriangle className="size-3.5" aria-hidden="true" />
                            This debits the client&rsquo;s balance. Return the money outside the platform
                            separately.
                        </span>
                    </Alert>

                    <Button type="submit" variant="danger" loading={processing} className="w-full">
                        Issue refund
                    </Button>
                </form>
            </CardBody>
        </Card>
    );
}

function Summary({ label, value }: { label: string; value: string }) {
    return (
        <Card>
            <CardBody className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
                <p className="text-xl font-semibold tracking-tight tabular-nums">{value}</p>
            </CardBody>
        </Card>
    );
}
