import { Link, useForm } from '@inertiajs/react';
import { Megaphone, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';
import type { Paginated } from '@/Types';

export interface CampaignRow {
    public_id: string;
    name: string;
    provider: string;
    providerLabel: string;
    objective: string;
    status: string;
    statusLabel: string;
    statusMessage: string;
    currency: string;
    budget: string;
    budgetTypeLabel: string;
    chargedTotal: string;
    captured: string;
    remaining: string;
    reportedSpend: string;
    editable: boolean;
    live: boolean;
    submittedAt: string | null;
}

export interface CampaignOptions {
    providers: {
        value: string;
        label: string;
        objectives: { value: string; label: string; description: string }[];
    }[];
    budgetTypes: {
        value: string;
        label: string;
        description: string;
        requiresEndDate: boolean;
    }[];
    bidStrategies: {
        value: string;
        label: string;
        description: string;
        requiresAmount: boolean;
    }[];
}

export default function CampaignsIndex({
    campaigns,
    options,
    can,
}: {
    campaigns: Paginated<CampaignRow>;
    options: CampaignOptions;
    can: { create: boolean };
}) {
    const [showForm, setShowForm] = useState(false);

    return (
        <ClientLayout
            title="Campaigns"
            description="Everything you have submitted, and everything currently running."
            actions={
                can.create ? (
                    <Button onClick={() => setShowForm((open) => !open)}>
                        <Plus aria-hidden="true" />
                        New campaign
                    </Button>
                ) : null
            }
        >
            {showForm ? <CreateForm options={options} onDone={() => setShowForm(false)} /> : null}

            <Card>
                <CardHeader title="Your campaigns" />

                {campaigns.data.length === 0 ? (
                    <EmptyState
                        icon={Megaphone}
                        title="No campaigns yet"
                        description="Create one, add an audience and an ad, and send it to us for review."
                    />
                ) : (
                    <Table caption="Campaigns">
                        <thead>
                            <tr>
                                <Th>Campaign</Th>
                                <Th>Status</Th>
                                <Th className="text-right">Budget</Th>
                                <Th className="text-right">Total charge</Th>
                                <Th className="text-right">Spent</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {campaigns.data.map((campaign) => (
                                <tr key={campaign.public_id}>
                                    <Td>
                                        <Link
                                            href={route('client.campaigns.show', campaign.public_id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {campaign.name}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {campaign.providerLabel} · {campaign.budgetTypeLabel}
                                        </p>
                                    </Td>
                                    <Td>
                                        <StatusBadge status={campaign.status} label={campaign.statusLabel} />
                                    </Td>
                                    <Td className="text-right tabular-nums">{campaign.budget}</Td>
                                    <Td className="text-right tabular-nums">{campaign.chargedTotal}</Td>
                                    <Td className="text-right tabular-nums">{campaign.captured}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </ClientLayout>
    );
}

function CreateForm({ options, onDone }: { options: CampaignOptions; onDone: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        provider: options.providers[0]?.value ?? '',
        objective: options.providers[0]?.objectives[0]?.value ?? '',
        budget_type: options.budgetTypes[0]?.value ?? '',
        budget_amount: '',
        starts_at: '',
        ends_at: '',
    });

    const provider = options.providers.find((entry) => entry.value === data.provider);
    const objective = provider?.objectives.find((entry) => entry.value === data.objective);
    const budgetType = options.budgetTypes.find((entry) => entry.value === data.budget_type);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('client.campaigns.store'), {
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <Card>
            <CardHeader
                title="New campaign"
                description="You will be shown the exact cost, including our fees, before anything is submitted."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Campaign name" error={errors.name} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Platform" error={errors.provider} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.provider}
                                onChange={(event) => {
                                    const next = options.providers.find(
                                        (entry) => entry.value === event.target.value,
                                    );

                                    setData((current) => ({
                                        ...current,
                                        provider: event.target.value,
                                        // Objectives differ per platform, so a
                                        // switch resets to one that exists there.
                                        objective: next?.objectives[0]?.value ?? '',
                                    }));
                                }}
                            >
                                {options.providers.map((entry) => (
                                    <option key={entry.value} value={entry.value}>
                                        {entry.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field
                        label="What do you want this campaign to do?"
                        hint={objective?.description}
                        error={errors.objective}
                        required
                    >
                        {(field) => (
                            <Select
                                {...field}
                                value={data.objective}
                                onChange={(event) => setData('objective', event.target.value)}
                            >
                                {(provider?.objectives ?? []).map((entry) => (
                                    <option key={entry.value} value={entry.value}>
                                        {entry.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field
                        label="Budget type"
                        hint={budgetType?.description}
                        error={errors.budget_type}
                        required
                    >
                        {(field) => (
                            <Select
                                {...field}
                                value={data.budget_type}
                                onChange={(event) => setData('budget_type', event.target.value)}
                            >
                                {options.budgetTypes.map((entry) => (
                                    <option key={entry.value} value={entry.value}>
                                        {entry.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field
                        label="Budget"
                        hint="Our fees are added on top, so this is what reaches the advertising platform."
                        error={errors.budget_amount}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                inputMode="decimal"
                                value={data.budget_amount}
                                onChange={(event) => setData('budget_amount', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Start date" error={errors.starts_at} required>
                        {(field) => (
                            <Input
                                {...field}
                                type="datetime-local"
                                value={data.starts_at}
                                onChange={(event) => setData('starts_at', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="End date"
                        hint={
                            budgetType?.requiresEndDate
                                ? 'Required for a daily budget, so the total commitment is known.'
                                : 'Optional.'
                        }
                        error={errors.ends_at}
                        required={budgetType?.requiresEndDate}
                    >
                        {(field) => (
                            <Input
                                {...field}
                                type="datetime-local"
                                value={data.ends_at}
                                onChange={(event) => setData('ends_at', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Create campaign
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
