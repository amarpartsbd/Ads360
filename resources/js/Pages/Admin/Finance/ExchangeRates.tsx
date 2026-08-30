import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface RateRow {
    id: string;
    pair: string;
    baseCurrency: string;
    quoteCurrency: string;
    marketRate: string;
    clientRate: string;
    markup: string;
    scope: string;
    effectiveFrom: string;
    effectiveUntil: string | null;
    current: boolean;
}

export default function ExchangeRates({
    rates,
    currencies,
    can,
}: {
    rates: Paginated<RateRow>;
    currencies: string[];
    can: { manage: boolean };
}) {
    return (
        <AdminLayout
            title="Exchange rates"
            description="Rates are never edited. Publishing a new one closes the previous, so historical transactions keep the terms they were made under."
        >
            {can.manage ? <PublishForm currencies={currencies} /> : null}

            <Card>
                <CardHeader title="Rate history" description={`${rates.total} rate(s) recorded.`} />
                <Table caption="Exchange rate history">
                    <thead>
                        <tr>
                            <Th>Pair</Th>
                            <Th>Scope</Th>
                            <Th className="text-right">Market</Th>
                            <Th className="text-right">Client</Th>
                            <Th className="text-right">Markup</Th>
                            <Th>Effective</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {rates.data.map((rate) => (
                            <tr key={rate.id}>
                                <Td className="font-medium">
                                    {rate.pair}
                                    {rate.current ? (
                                        <Badge tone="success" className="ml-2">
                                            Current
                                        </Badge>
                                    ) : null}
                                </Td>
                                <Td className="text-muted-foreground">{rate.scope}</Td>
                                <Td className="text-right text-muted-foreground tabular-nums">
                                    {rate.marketRate}
                                </Td>
                                <Td className="text-right font-medium tabular-nums">{rate.clientRate}</Td>
                                <Td className="text-right tabular-nums">{rate.markup}</Td>
                                <Td className="text-muted-foreground">
                                    {new Date(rate.effectiveFrom).toLocaleDateString()}
                                    {rate.effectiveUntil
                                        ? ` – ${new Date(rate.effectiveUntil).toLocaleDateString()}`
                                        : ' – now'}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>

                {rates.last_page > 1 ? (
                    <nav
                        aria-label="Pagination"
                        className="flex items-center justify-between gap-4 px-5 py-3"
                    >
                        <p className="text-sm text-muted-foreground">
                            Showing {rates.from ?? 0}–{rates.to ?? 0} of {rates.total}
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {rates.links.map((link, index) => (
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

function PublishForm({ currencies }: { currencies: string[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        base_currency: 'USD',
        quote_currency: 'BDT',
        market_rate: '',
        client_rate: '',
        note: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (
            !window.confirm(
                `Publish ${data.base_currency} → ${data.quote_currency} at ${data.client_rate}? All new transactions will use it.`,
            )
        ) {
            return;
        }

        post(route('admin.finance.exchange-rates.store'), {
            onSuccess: () => reset('market_rate', 'client_rate', 'note'),
        });
    };

    return (
        <Card>
            <CardHeader
                title="Publish a rate"
                description="The client rate is what clients transact at; the difference from market is the platform's markup."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Field label="From" error={errors.base_currency} required>
                        {(props) => (
                            <Select
                                {...props}
                                value={data.base_currency}
                                onChange={(event) => setData('base_currency', event.target.value)}
                            >
                                {currencies.map((code) => (
                                    <option key={code} value={code}>
                                        {code}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="To" error={errors.quote_currency} required>
                        {(props) => (
                            <Select
                                {...props}
                                value={data.quote_currency}
                                onChange={(event) => setData('quote_currency', event.target.value)}
                            >
                                {currencies.map((code) => (
                                    <option key={code} value={code}>
                                        {code}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="Market rate" error={errors.market_rate} required>
                        {(props) => (
                            <Input
                                {...props}
                                inputMode="decimal"
                                placeholder="120.00"
                                value={data.market_rate}
                                onChange={(event) => setData('market_rate', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Client rate" error={errors.client_rate} required>
                        {(props) => (
                            <Input
                                {...props}
                                inputMode="decimal"
                                placeholder="123.60"
                                value={data.client_rate}
                                onChange={(event) => setData('client_rate', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="flex items-end">
                        <Button type="submit" loading={processing} className="w-full">
                            Publish
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
