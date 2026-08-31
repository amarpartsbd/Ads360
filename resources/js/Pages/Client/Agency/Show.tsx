import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, UserMinus, UserPlus } from 'lucide-react';
import type { FormEvent } from 'react';
import { StatTile } from '@/Components/Analytics/StatTile';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';
import type { SerialisedMoney } from '@/Types';

interface Summary {
    balance: SerialisedMoney;
    spend: SerialisedMoney | null;
    impressions: number | null;
    clicks: number | null;
    conversions: number | null;
    activeCampaigns: number;
    totalCampaigns: number;
    verified: boolean;
    canSpend: boolean;
}

interface Person {
    id: string;
    name: string;
    email: string;
}

export default function AgencyClientShow({
    client,
    summary,
    window: reportWindow,
    assigned,
    assignable,
    roles,
    can,
}: {
    client: {
        id: string;
        name: string;
        status: string;
        statusLabel: string;
        currency: string;
        contactEmail: string | null;
        website: string | null;
    };
    summary: Summary | null;
    window: { since: string; until: string };
    assigned: Person[];
    assignable: Person[];
    roles: { slug: string; label: string }[];
    can: { manageStaff: boolean };
}) {
    return (
        <ClientLayout
            title={client.name}
            description={client.contactEmail ?? 'A client of your agency.'}
            actions={
                <div className="flex gap-2">
                    <Button
                        variant="ghost"
                        onClick={() => router.post(route('client.clients.open', { client: client.id }))}
                    >
                        Open workspace
                    </Button>
                </div>
            }
        >
            <Link
                href={route('client.clients.index')}
                className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                All clients
            </Link>

            {summary && !summary.verified ? (
                <Alert tone="warning" title="Not verified yet">
                    You can build campaigns for {client.name} now, but nothing will run until our compliance
                    team has verified their business. That decision is not one an agency can make for its own
                    client.
                </Alert>
            ) : null}

            <Card>
                <CardHeader
                    title="This client"
                    description={`Performance from ${reportWindow.since} to ${reportWindow.until}.`}
                />
                <CardBody className="space-y-4">
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <StatusBadge status={client.status} label={client.statusLabel} />
                        <span className="text-muted-foreground">Billed in {client.currency}</span>
                        {client.website ? (
                            <a
                                href={client.website}
                                className="underline underline-offset-4"
                                rel="noreferrer noopener"
                                target="_blank"
                            >
                                {client.website}
                            </a>
                        ) : null}
                    </div>

                    {summary ? (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatTile label="Available balance" value={summary.balance.formatted} />
                            <StatTile
                                label="Spend"
                                value={summary.spend?.formatted ?? 'Not reported'}
                                hint={summary.spend ? undefined : 'Nothing has been reported for this window'}
                            />
                            <StatTile
                                label="Campaigns"
                                value={`${summary.activeCampaigns} of ${summary.totalCampaigns}`}
                                hint="Active of total"
                            />
                            <StatTile
                                label="Clicks"
                                value={summary.clicks === null ? '—' : summary.clicks.toLocaleString()}
                                hint={
                                    summary.conversions === null
                                        ? undefined
                                        : `${summary.conversions.toLocaleString()} conversions`
                                }
                            />
                        </div>
                    ) : null}
                </CardBody>
            </Card>

            <Card>
                <CardHeader
                    title="Who works on this client"
                    description="Owners and admins reach every client already, so only assignable staff appear here."
                />

                {assigned.length === 0 ? (
                    <EmptyState
                        icon={UserPlus}
                        title="Nobody assigned"
                        description="Assign a manager or a member of staff so they can see this client's campaigns."
                    />
                ) : (
                    <Table caption="Staff assigned to this client">
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Email</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {assigned.map((person) => (
                                <tr key={person.id}>
                                    <Td>{person.name}</Td>
                                    <Td className="text-muted-foreground">{person.email}</Td>
                                    <Td className="text-right">
                                        {can.manageStaff ? (
                                            <Button
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        route('client.clients.staff.remove', {
                                                            client: client.id,
                                                            member: person.id,
                                                        }),
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <UserMinus className="size-4" aria-hidden="true" />
                                                Remove
                                            </Button>
                                        ) : null}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            {can.manageStaff && assignable.length > 0 ? (
                <AssignForm clientId={client.id} people={assignable} roles={roles} />
            ) : null}
        </ClientLayout>
    );
}

function AssignForm({
    clientId,
    people,
    roles,
}: {
    clientId: string;
    people: Person[];
    roles: { slug: string; label: string }[];
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        user: people[0]?.id ?? '',
        role: roles[0]?.slug ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('client.clients.staff.assign', { client: clientId }), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <Card>
            <CardHeader title="Assign someone" />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-3">
                    <Field label="Person" error={errors.user} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.user}
                                onChange={(event) => setData('user', event.target.value)}
                            >
                                {people.map((person) => (
                                    <option key={person.id} value={person.id}>
                                        {person.name} ({person.email})
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="Role" error={errors.role} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.role}
                                onChange={(event) => setData('role', event.target.value)}
                            >
                                {roles.map((role) => (
                                    <option key={role.slug} value={role.slug}>
                                        {role.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <div className="flex items-end">
                        <Button type="submit" loading={processing}>
                            <UserPlus className="size-4" aria-hidden="true" />
                            Assign
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
