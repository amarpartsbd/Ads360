import { Link, router, useForm } from '@inertiajs/react';
import { Building2, Plus, Users } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { StatTile } from '@/Components/Analytics/StatTile';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';
import type { SerialisedMoney } from '@/Types';

interface ClientRow {
    id: string;
    name: string;
    status: string;
    statusLabel: string;
    verified: boolean;
    canSpend: boolean;
    balance: SerialisedMoney;
    /** Null means nothing was reported for the window, which is not zero. */
    spend: SerialisedMoney | null;
    impressions: number | null;
    clicks: number | null;
    conversions: number | null;
    activeCampaigns: number;
    totalCampaigns: number;
    assignedStaff: number;
}

interface Totals {
    clients: number;
    activeCampaigns: number;
    impressions: number;
    clicks: number;
    conversions: number;
    spend: SerialisedMoney | null;
    balance: SerialisedMoney | null;
    currencies: string[];
    spansCurrencies: boolean;
}

interface PricingRule {
    feeLabel: string;
    value: string;
    appliesFrom: string | null;
}

interface Pricing {
    name: string | null;
    isAgencyRate: boolean;
    currency?: string;
    rules: PricingRule[];
}

export default function AgencyClientsIndex({
    agency,
    window: reportWindow,
    clients,
    totals,
    pricing,
    can,
}: {
    agency: { name: string; type: string };
    window: { since: string; until: string };
    clients: ClientRow[];
    totals: Totals;
    pricing: Pricing | null;
    can: { createClient: boolean; manageStaff: boolean };
}) {
    const [adding, setAdding] = useState(false);

    return (
        <ClientLayout
            title="Clients"
            description={`The businesses ${agency.name} manages.`}
            actions={
                can.createClient ? (
                    <Button onClick={() => setAdding((open) => !open)}>
                        <Plus className="size-4" aria-hidden="true" />
                        Add a client
                    </Button>
                ) : undefined
            }
        >
            {adding && can.createClient ? <AddClientForm onDone={() => setAdding(false)} /> : null}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Clients" value={totals.clients.toLocaleString()} />
                <StatTile
                    label="Active campaigns"
                    value={totals.activeCampaigns.toLocaleString()}
                    hint={`${reportWindow.since} to ${reportWindow.until}`}
                />
                <StatTile
                    label="Spend"
                    value={totals.spend?.formatted ?? '—'}
                    hint={
                        totals.spansCurrencies
                            ? `Not totalled: clients bill in ${totals.currencies.join(', ')}`
                            : undefined
                    }
                />
                <StatTile
                    label="Available balance"
                    value={totals.balance?.formatted ?? '—'}
                    hint={totals.spansCurrencies ? 'Not totalled across currencies' : undefined}
                />
            </div>

            {totals.spansCurrencies ? (
                <Alert tone="info" title="Totals are shown per client">
                    Your clients are billed in more than one currency, so adding their figures together would
                    not produce a real amount. Each client's own currency is shown on its row.
                </Alert>
            ) : null}

            {pricing && pricing.name ? <PricingCard pricing={pricing} /> : null}

            <Card>
                <CardHeader
                    title="Your clients"
                    description={`Performance from ${reportWindow.since} to ${reportWindow.until}.`}
                />

                {clients.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No clients yet"
                        description={
                            can.createClient
                                ? 'Add the first business you manage and start building campaigns for them.'
                                : 'You have not been assigned to any clients yet.'
                        }
                    />
                ) : (
                    <Table caption="Clients this agency manages">
                        <thead>
                            <tr>
                                <Th>Client</Th>
                                <Th>Status</Th>
                                <Th className="text-right">Balance</Th>
                                <Th className="text-right">Spend</Th>
                                <Th className="text-right">Campaigns</Th>
                                <Th className="text-right">Team</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {clients.map((client) => (
                                <tr key={client.id}>
                                    <Td>
                                        <Link
                                            href={route('client.clients.show', { client: client.id })}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {client.name}
                                        </Link>
                                        {!client.verified ? (
                                            <p className="text-xs text-muted-foreground">
                                                Awaiting business verification
                                            </p>
                                        ) : null}
                                    </Td>
                                    <Td>
                                        <StatusBadge status={client.status} label={client.statusLabel} />
                                    </Td>
                                    <Td className="text-right tabular-nums">{client.balance.formatted}</Td>
                                    <Td className="text-right tabular-nums">
                                        {/* Nothing reported is not nothing spent. */}
                                        {client.spend?.formatted ?? (
                                            <span className="text-muted-foreground">Not reported</span>
                                        )}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {client.activeCampaigns} of {client.totalCampaigns}
                                    </Td>
                                    <Td className="text-right tabular-nums">{client.assignedStaff}</Td>
                                    <Td className="text-right">
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    route('client.clients.open', { client: client.id }),
                                                )
                                            }
                                        >
                                            Open
                                        </Button>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </ClientLayout>
    );
}

/**
 * What the agency pays the platform.
 *
 * Read-only on purpose: these are the platform's fees, and an agency setting
 * its own would be setting what it is charged (spec §36).
 */
function PricingCard({ pricing }: { pricing: Pricing }) {
    return (
        <Card>
            <CardHeader
                title="Your fee schedule"
                description={
                    pricing.isAgencyRate
                        ? 'Negotiated for your agency. It applies to every client you manage.'
                        : 'The platform standard. Talk to us about an agency rate.'
                }
            />
            <CardBody>
                <p className="mb-3 text-sm font-medium">{pricing.name}</p>
                <Table caption="Fees on your schedule">
                    <thead>
                        <tr>
                            <Th>Fee</Th>
                            <Th className="text-right">Rate</Th>
                            <Th>From</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {pricing.rules.map((rule, index) => (
                            <tr key={index}>
                                <Td>{rule.feeLabel}</Td>
                                <Td className="text-right tabular-nums">{rule.value}</Td>
                                <Td className="text-muted-foreground">
                                    {rule.appliesFrom ?? 'Every amount'}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </CardBody>
        </Card>
    );
}

function AddClientForm({ onDone }: { onDone: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        legal_name: '',
        contact_email: '',
        website: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('client.clients.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <Card>
            <CardHeader
                title="Add a client"
                description="They will need business verification before their campaigns can run — that stays a platform decision."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Business name" error={errors.name} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Registered name" hint="If different." error={errors.legal_name}>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.legal_name}
                                onChange={(event) => setData('legal_name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Contact email" error={errors.contact_email}>
                        {(field) => (
                            <Input
                                {...field}
                                type="email"
                                value={data.contact_email}
                                onChange={(event) => setData('contact_email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Website" error={errors.website}>
                        {(field) => (
                            <Input
                                {...field}
                                type="url"
                                placeholder="https://"
                                value={data.website}
                                onChange={(event) => setData('website', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            <Users className="size-4" aria-hidden="true" />
                            Add client
                        </Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
