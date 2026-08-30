import { Link } from '@inertiajs/react';
import { Clock, Lock, Plus, Wallet as WalletIcon } from 'lucide-react';
import { Alert } from '@/Components/UI/Alert';
import { BalanceCard } from '@/Components/Finance/BalanceCard';
import { LedgerTable, type LedgerRow } from '@/Components/Finance/LedgerTable';
import type { SerialisedMoney } from '@/Components/Finance/MoneyValue';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';

export interface WalletSummary {
    id: string;
    currency: string;
    status: string;
    statusLabel: string;
    available: SerialisedMoney;
    reserved: SerialisedMoney;
    total: SerialisedMoney;
}

export interface PendingDeposit {
    id: string;
    reference: string;
    method: string;
    methodLabel: string;
    amount: string;
    status: string;
    statusLabel: string;
    externalReference: string | null;
    rejectionReason: string | null;
    submittedAt: string | null;
}

export default function WalletOverview({
    wallet,
    recentEntries,
    pendingDeposits,
    can,
}: {
    wallet: WalletSummary;
    recentEntries: LedgerRow[];
    pendingDeposits: PendingDeposit[];
    can: { deposit: boolean };
}) {
    return (
        <ClientLayout
            title="Wallet"
            description="Your balance, and everything that has moved through it."
            actions={
                can.deposit ? (
                    <Button asChild>
                        <Link href={route('client.wallet.add-funds')}>
                            <Plus aria-hidden="true" />
                            Add funds
                        </Link>
                    </Button>
                ) : null
            }
        >
            {wallet.status !== 'ACTIVE' ? (
                <Alert tone="warning" title={`This wallet is ${wallet.statusLabel.toLowerCase()}`}>
                    Funds cannot leave the wallet while it is in this state. Contact support if this is
                    unexpected.
                </Alert>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-3">
                <BalanceCard
                    label="Available"
                    value={wallet.available}
                    hint="Ready to commit to campaigns"
                    icon={WalletIcon}
                />
                <BalanceCard
                    label="Reserved"
                    value={wallet.reserved}
                    hint="Held against approved campaigns"
                    icon={Lock}
                />
                <BalanceCard label="Total held" value={wallet.total} />
            </div>

            {pendingDeposits.length > 0 ? (
                <Card>
                    <CardHeader
                        title="Deposits in progress"
                        description="These are waiting for our finance team to confirm."
                    />
                    <Table caption="Deposits awaiting verification">
                        <thead>
                            <tr>
                                <Th>Reference</Th>
                                <Th>Method</Th>
                                <Th className="text-right">Amount</Th>
                                <Th>Status</Th>
                                <Th>Submitted</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {pendingDeposits.map((deposit) => (
                                <tr key={deposit.id}>
                                    <Td className="font-mono text-xs">{deposit.reference}</Td>
                                    <Td>{deposit.methodLabel}</Td>
                                    <Td className="text-right tabular-nums">{deposit.amount}</Td>
                                    <Td>
                                        <StatusBadge
                                            status={
                                                deposit.status === 'AWAITING_VERIFICATION'
                                                    ? 'PENDING'
                                                    : deposit.status
                                            }
                                            label={deposit.statusLabel}
                                        />
                                    </Td>
                                    <Td className="text-muted-foreground">
                                        {deposit.submittedAt
                                            ? new Date(deposit.submittedAt).toLocaleDateString()
                                            : '—'}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            ) : null}

            <Card>
                <CardHeader
                    title="Recent activity"
                    description="The last ten movements."
                    action={
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('client.wallet.transactions')}>
                                <Clock aria-hidden="true" />
                                Full statement
                            </Link>
                        </Button>
                    }
                />
                <LedgerTable entries={recentEntries} />
            </Card>
        </ClientLayout>
    );
}
