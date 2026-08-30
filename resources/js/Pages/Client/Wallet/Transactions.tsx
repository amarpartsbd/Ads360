import { router } from '@inertiajs/react';
import { LedgerTable, type LedgerRow } from '@/Components/Finance/LedgerTable';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { Select } from '@/Components/UI/Select';
import ClientLayout from '@/Layouts/ClientLayout';
import type { Paginated } from '@/Types';
import type { WalletSummary } from './Overview';

const TYPES = [
    ['', 'All activity'],
    ['DEPOSIT', 'Deposits'],
    ['CAMPAIGN_SPEND', 'Campaign spend'],
    ['SERVICE_FEE', 'Service fees'],
    ['MANAGEMENT_FEE', 'Management fees'],
    ['TAX', 'Tax'],
    ['RESERVE', 'Budget reserved'],
    ['RELEASE', 'Budget released'],
    ['REFUND', 'Refunds'],
    ['ADJUSTMENT', 'Adjustments'],
    ['REVERSAL', 'Reversals'],
] as const;

export default function WalletTransactions({
    wallet,
    entries,
    filters,
}: {
    wallet: WalletSummary;
    entries: Paginated<LedgerRow>;
    filters: { type: string | null };
}) {
    return (
        <ClientLayout
            title="Statement"
            description={`Every movement through your ${wallet.currency} wallet.`}
        >
            <Card>
                <CardHeader
                    title="Transactions"
                    description={`${entries.total} entries.`}
                    action={
                        <>
                            <label htmlFor="type-filter" className="sr-only">
                                Filter by type
                            </label>
                            <Select
                                id="type-filter"
                                className="w-52"
                                value={filters.type ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        route('client.wallet.transactions'),
                                        event.target.value ? { type: event.target.value } : {},
                                        { preserveState: true, replace: true },
                                    )
                                }
                            >
                                {TYPES.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </Select>
                        </>
                    }
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
        </ClientLayout>
    );
}
