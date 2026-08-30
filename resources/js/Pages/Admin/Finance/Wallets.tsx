import { Link, router } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Input } from '@/Components/UI/Input';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface WalletRow {
    id: string;
    organization: string;
    tenant: string;
    currency: string;
    available: string;
    reserved: string;
    total: string;
    status: string;
    statusLabel: string;
    url: string;
}

export default function Wallets({
    wallets,
    filters,
    liability,
}: {
    wallets: Paginated<WalletRow>;
    filters: { search: string };
    liability: { currency: string; amount: string }[];
}) {
    const [search, setSearch] = useState(filters.search);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('admin.finance.wallets.index'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AdminLayout title="Wallets" description="Client balances held by the platform.">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {liability.map((entry) => (
                    <Card key={entry.currency}>
                        <CardBody className="space-y-1">
                            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Client liability ({entry.currency})
                            </p>
                            <p className="text-2xl font-semibold tracking-tight tabular-nums">
                                {entry.amount}
                            </p>
                            <p className="text-xs text-muted-foreground">Held on behalf of clients</p>
                        </CardBody>
                    </Card>
                ))}
            </div>

            <Card>
                <CardHeader
                    title="All wallets"
                    description={`${wallets.total} wallet(s).`}
                    action={
                        <form onSubmit={submit} className="flex gap-2">
                            <label htmlFor="wallet-search" className="sr-only">
                                Search by client
                            </label>
                            <Input
                                id="wallet-search"
                                type="search"
                                placeholder="Search by client"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                className="w-56"
                            />
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                    }
                />

                {wallets.data.length === 0 ? (
                    <EmptyState icon={Wallet} title="No wallets match" />
                ) : (
                    <Table caption="Client wallets">
                        <thead>
                            <tr>
                                <Th>Client</Th>
                                <Th className="text-right">Available</Th>
                                <Th className="text-right">Reserved</Th>
                                <Th className="text-right">Total</Th>
                                <Th>Status</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {wallets.data.map((wallet) => (
                                <tr key={wallet.id}>
                                    <Td>
                                        <Link
                                            href={wallet.url}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {wallet.organization}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {wallet.tenant}
                                        </span>
                                    </Td>
                                    <Td className="text-right font-medium tabular-nums">
                                        {wallet.available}
                                    </Td>
                                    <Td className="text-right text-muted-foreground tabular-nums">
                                        {wallet.reserved}
                                    </Td>
                                    <Td className="text-right tabular-nums">{wallet.total}</Td>
                                    <Td>
                                        <StatusBadge status={wallet.status} label={wallet.statusLabel} />
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
