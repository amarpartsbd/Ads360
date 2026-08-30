import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { Badge } from '@/Components/UI/Badge';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Table, Td, Th } from '@/Components/UI/Table';
import { Receipt } from 'lucide-react';

export interface LedgerRow {
    id: string;
    type: string;
    typeLabel: string;
    description: string;
    isCredit: boolean;
    amount: string;
    balanceAfter: string;
    at: string | null;
}

/**
 * The statement view of a wallet.
 *
 * Direction is shown with an arrow and a sign as well as colour, so it does not
 * depend on colour alone (spec §74).
 */
export function LedgerTable({ entries }: { entries: LedgerRow[] }) {
    if (entries.length === 0) {
        return (
            <EmptyState
                icon={Receipt}
                title="No transactions yet"
                description="Wallet activity will appear here once funds move."
            />
        );
    }

    return (
        <Table caption="Wallet transactions">
            <thead>
                <tr>
                    <Th>Description</Th>
                    <Th>Type</Th>
                    <Th className="text-right">Amount</Th>
                    <Th className="text-right">Balance</Th>
                    <Th>When</Th>
                </tr>
            </thead>
            <tbody>
                {entries.map((entry) => (
                    <tr key={entry.id}>
                        <Td className="font-medium">{entry.description}</Td>
                        <Td>
                            <Badge>{entry.typeLabel}</Badge>
                        </Td>
                        <Td className="text-right">
                            <span
                                className={
                                    entry.isCredit
                                        ? 'inline-flex items-center gap-1 font-medium text-success tabular-nums'
                                        : 'inline-flex items-center gap-1 font-medium tabular-nums'
                                }
                            >
                                {entry.isCredit ? (
                                    <ArrowDownLeft className="size-3.5" aria-hidden="true" />
                                ) : (
                                    <ArrowUpRight className="size-3.5" aria-hidden="true" />
                                )}
                                <span className="sr-only">{entry.isCredit ? 'Credit' : 'Debit'} </span>
                                {entry.isCredit ? '+' : '−'}
                                {entry.amount}
                            </span>
                        </Td>
                        <Td className="text-right text-muted-foreground tabular-nums">
                            {entry.balanceAfter}
                        </Td>
                        <Td className="text-muted-foreground">
                            {entry.at ? new Date(entry.at).toLocaleString() : '—'}
                        </Td>
                    </tr>
                ))}
            </tbody>
        </Table>
    );
}
