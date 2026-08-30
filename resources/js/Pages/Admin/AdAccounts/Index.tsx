import { Link, router, useForm } from '@inertiajs/react';
import { Plus, Server } from 'lucide-react';
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
import type { Paginated } from '@/Types';

export interface AdAccountRow {
    public_id: string;
    provider: string;
    providerLabel: string;
    external_account_id: string;
    name: string;
    currency: string;
    status: string;
    statusLabel: string;
    health_status: string;
    healthLabel: string;
    billing_status: string;
    billingLabel: string;
    needsAttention: boolean;
    allocatable: boolean;
    dailySpendLimit: string | null;
    currentDailySpend: string;
    dailyHeadroom: string | null;
    dailyUtilisation: number | null;
    lastSyncedAt: string | null;
}

interface Option {
    value: string;
    label: string;
}

/**
 * The managed ad account inventory (spec §17).
 *
 * Every money figure arrives already formatted. The page displays strings; it
 * never adds them up (Rule 8).
 */
export default function AdAccountsIndex({
    accounts,
    filters,
    options,
    can,
}: {
    accounts: Paginated<AdAccountRow>;
    filters: { provider: string | null; status: string | null; attention: boolean };
    options: {
        providers: Option[];
        statuses: Option[];
        currencies: Option[];
    };
    can: { create: boolean };
}) {
    const [showForm, setShowForm] = useState(false);

    const applyFilters = (next: Partial<typeof filters>) => {
        router.get(
            route('admin.ad-accounts.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AdminLayout
            title="Ad accounts"
            description="The advertising accounts the platform operates on clients' behalf."
            actions={
                can.create ? (
                    <Button onClick={() => setShowForm((open) => !open)}>
                        <Plus aria-hidden="true" />
                        Register account
                    </Button>
                ) : null
            }
        >
            {showForm ? <RegisterForm options={options} onDone={() => setShowForm(false)} /> : null}

            <Card>
                <CardHeader
                    title="Inventory"
                    description="Accounts needing attention are the ones allocation will refuse to use."
                    action={
                        <div className="flex flex-wrap items-center gap-2">
                            <Select
                                aria-label="Filter by provider"
                                value={filters.provider ?? ''}
                                onChange={(event) => applyFilters({ provider: event.target.value || null })}
                            >
                                <option value="">All providers</option>
                                {options.providers.map((provider) => (
                                    <option key={provider.value} value={provider.value}>
                                        {provider.label}
                                    </option>
                                ))}
                            </Select>

                            <Select
                                aria-label="Filter by status"
                                value={filters.status ?? ''}
                                onChange={(event) => applyFilters({ status: event.target.value || null })}
                            >
                                <option value="">All statuses</option>
                                {options.statuses.map((status) => (
                                    <option key={status.value} value={status.value}>
                                        {status.label}
                                    </option>
                                ))}
                            </Select>

                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={filters.attention}
                                    onChange={(event) => applyFilters({ attention: event.target.checked })}
                                />
                                Needs attention
                            </label>
                        </div>
                    }
                />

                {accounts.data.length === 0 ? (
                    <EmptyState
                        icon={Server}
                        title="No accounts match"
                        description="Register a provider account, or clear the filters above."
                    />
                ) : (
                    <Table caption="Managed ad accounts">
                        <thead>
                            <tr>
                                <Th>Account</Th>
                                <Th>Status</Th>
                                <Th>Health</Th>
                                <Th>Billing</Th>
                                <Th className="text-right">Today</Th>
                                <Th className="text-right">Headroom</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.data.map((account) => (
                                <tr key={account.public_id}>
                                    <Td>
                                        <Link
                                            href={route('admin.ad-accounts.show', account.public_id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {account.name}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {account.providerLabel} · {account.currency}
                                        </p>
                                    </Td>
                                    <Td>
                                        <StatusBadge status={account.status} label={account.statusLabel} />
                                    </Td>
                                    <Td>
                                        <StatusBadge
                                            status={account.health_status}
                                            label={account.healthLabel}
                                        />
                                    </Td>
                                    <Td>
                                        <StatusBadge
                                            status={account.billing_status}
                                            label={account.billingLabel}
                                        />
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {account.currentDailySpend}
                                        {account.dailyUtilisation !== null ? (
                                            <span className="block text-xs text-muted-foreground">
                                                {account.dailyUtilisation}% of limit
                                            </span>
                                        ) : null}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {account.dailyHeadroom ?? 'No limit set'}
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

function RegisterForm({
    options,
    onDone,
}: {
    options: { providers: Option[]; currencies: Option[] };
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        provider: options.providers[0]?.value ?? '',
        external_account_id: '',
        name: '',
        currency: 'BDT',
        timezone: 'Asia/Dhaka',
        daily_spend_limit: '',
        monthly_spend_limit: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('admin.ad-accounts.store'), {
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <Card>
            <CardHeader
                title="Register a provider account"
                description="The account is added as pending. Confirm its billing and limits before activating it."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
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

                    <Field
                        label="Provider account ID"
                        hint="Exactly as the provider shows it."
                        error={errors.external_account_id}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                value={data.external_account_id}
                                onChange={(event) => setData('external_account_id', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Name" error={errors.name} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                            />
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
                        label="Daily spend limit"
                        hint="Leave blank for no limit. Entered in the account's own currency."
                        error={errors.daily_spend_limit}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                inputMode="decimal"
                                value={data.daily_spend_limit}
                                onChange={(event) => setData('daily_spend_limit', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="flex items-end gap-2 sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Register account
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
