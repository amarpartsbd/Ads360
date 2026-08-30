import { Link, useForm } from '@inertiajs/react';
import { Layers, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';

export interface PoolRow {
    id: string;
    name: string;
    slug: string;
    provider: string;
    providerLabel: string;
    currency: string;
    status: string;
    statusLabel: string;
    strategy: string;
    strategyLabel: string;
    strategyDescription: string;
    priority: number;
    memberCount: number;
    allocatable: boolean;
}

export interface PoolOptions {
    providers: { value: string; label: string }[];
    strategies: { value: string; label: string; description: string; usesWeight: boolean }[];
    statuses: { value: string; label: string }[];
    currencies: { value: string; label: string }[];
}

/**
 * Ad account pools (spec §18).
 *
 * A pool is provider- and currency-homogeneous, which is why both are fixed at
 * creation and absent from the edit form.
 */
export default function PoolsIndex({
    pools,
    options,
    can,
}: {
    pools: PoolRow[];
    options: PoolOptions;
    can: { create: boolean };
}) {
    const [showForm, setShowForm] = useState(false);

    return (
        <AdminLayout
            title="Account pools"
            description="Named groups of ad accounts, with the rules allocation draws them by."
            actions={
                can.create ? (
                    <Button onClick={() => setShowForm((open) => !open)}>
                        <Plus aria-hidden="true" />
                        New pool
                    </Button>
                ) : null
            }
        >
            {showForm ? <CreateForm options={options} onDone={() => setShowForm(false)} /> : null}

            <Card>
                <CardHeader title="Pools" description="Only active pools are used by allocation." />

                {pools.length === 0 ? (
                    <EmptyState
                        icon={Layers}
                        title="No pools yet"
                        description="A pool groups accounts of one provider and currency, and carries the rules for handing them out."
                    />
                ) : (
                    <Table caption="Ad account pools">
                        <thead>
                            <tr>
                                <Th>Pool</Th>
                                <Th>Status</Th>
                                <Th>Strategy</Th>
                                <Th className="text-right">Accounts</Th>
                                <Th className="text-right">Priority</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {pools.map((pool) => (
                                <tr key={pool.id}>
                                    <Td>
                                        <Link
                                            href={route('admin.ad-account-pools.show', pool.id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {pool.name}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {pool.providerLabel} · {pool.currency}
                                        </p>
                                    </Td>
                                    <Td>
                                        <StatusBadge status={pool.status} label={pool.statusLabel} />
                                    </Td>
                                    <Td>
                                        <span className="font-medium">{pool.strategyLabel}</span>
                                        <p className="text-xs text-muted-foreground">
                                            {pool.strategyDescription}
                                        </p>
                                    </Td>
                                    <Td className="text-right tabular-nums">{pool.memberCount}</Td>
                                    <Td className="text-right tabular-nums">{pool.priority}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </AdminLayout>
    );
}

function CreateForm({ options, onDone }: { options: PoolOptions; onDone: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        description: '',
        provider: options.providers[0]?.value ?? '',
        currency: 'BDT',
        selection_strategy: options.strategies[0]?.value ?? '',
        priority: 50,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('admin.ad-account-pools.store'), {
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    const strategy = options.strategies.find((option) => option.value === data.selection_strategy);

    return (
        <Card>
            <CardHeader
                title="Create a pool"
                description="Provider and currency are fixed once the pool exists: a pool that mixed either could not compare its own members."
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

                    <Field label="Provider" error={errors.provider} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.provider}
                                onChange={(event) => setData('provider', event.target.value)}
                            >
                                {options.providers.map((provider) => (
                                    <option key={provider.value} value={provider.value}>
                                        {provider.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="Currency" error={errors.currency} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.currency}
                                onChange={(event) => setData('currency', event.target.value)}
                            >
                                {options.currencies.map((currency) => (
                                    <option key={currency.value} value={currency.value}>
                                        {currency.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field
                        label="Selection strategy"
                        hint={strategy?.description}
                        error={errors.selection_strategy}
                        required
                    >
                        {(field) => (
                            <Select
                                {...field}
                                value={data.selection_strategy}
                                onChange={(event) => setData('selection_strategy', event.target.value)}
                            >
                                {options.strategies.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field
                        label="Priority"
                        hint="Used to order pools when more than one accepts a client."
                        error={errors.priority}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                type="number"
                                min={0}
                                max={100}
                                value={data.priority}
                                onChange={(event) => setData('priority', Number(event.target.value))}
                            />
                        )}
                    </Field>

                    <Field label="Description" error={errors.description}>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.description}
                                onChange={(event) => setData('description', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Create pool
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
