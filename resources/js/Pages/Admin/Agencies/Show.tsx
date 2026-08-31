import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Receipt } from 'lucide-react';
import type { FormEvent } from 'react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';

interface PlanRule {
    feeLabel: string;
    value: string;
    appliesFrom: string | null;
}

interface Plan {
    id: string;
    name: string;
    description: string | null;
    scope: string;
    scopeLabel: string;
    currency: string;
    isDefault: boolean;
    rules: PlanRule[];
}

interface ClientRow {
    id: string;
    name: string;
    status: string;
    statusLabel: string;
    isHouseAccount: boolean;
}

interface StaffRow {
    id: string;
    name: string;
    email: string;
    status: string;
    reachesEveryClient: boolean;
    roles: string[];
}

export default function AgencyShow({
    agency,
    clients,
    staff,
    plan,
    templates,
    can,
}: {
    agency: {
        id: string;
        name: string;
        typeLabel: string;
        status: string;
        statusLabel: string;
        currency: string;
        billingEmail: string | null;
    };
    clients: ClientRow[];
    staff: StaffRow[];
    plan: Plan | null;
    templates: Plan[];
    can: { assignPricing: boolean };
}) {
    return (
        <AdminLayout title={agency.name} description={`${agency.typeLabel} · ${agency.currency}`}>
            <Link
                href={route('admin.agencies.index')}
                className="inline-flex items-center gap-1.5 text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                All agencies
            </Link>

            <Card>
                <CardHeader title="Fee schedule" description="What this agency pays the platform." />
                <CardBody className="space-y-4">
                    {plan === null ? (
                        <p className="text-sm text-muted-foreground">
                            No schedule of their own — their clients are priced by the platform default.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            <p className="text-sm font-medium">{plan.name}</p>
                            <Table caption="Fees on this schedule">
                                <thead>
                                    <tr>
                                        <Th>Fee</Th>
                                        <Th className="text-right">Rate</Th>
                                        <Th>From</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {plan.rules.map((rule, index) => (
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
                        </div>
                    )}

                    {can.assignPricing ? <AssignPlanForm agencyId={agency.id} templates={templates} /> : null}
                </CardBody>
            </Card>

            <Card>
                <CardHeader
                    title="Workspaces"
                    description="The agency's own account, and every client it manages."
                />
                <Table caption="Organizations under this agency">
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Status</Th>
                            <Th />
                        </tr>
                    </thead>
                    <tbody>
                        {clients.map((client) => (
                            <tr key={client.id}>
                                <Td>{client.name}</Td>
                                <Td>
                                    <StatusBadge status={client.status} label={client.statusLabel} />
                                </Td>
                                <Td>
                                    {client.isHouseAccount ? (
                                        <Badge tone="info">The agency itself</Badge>
                                    ) : null}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Card>

            <Card>
                <CardHeader
                    title="People"
                    description="Who works at this agency, and how far each reaches."
                />

                {staff.length === 0 ? (
                    <EmptyState
                        icon={Receipt}
                        title="Nobody yet"
                        description="The agency's owner is created when it is provisioned."
                    />
                ) : (
                    <Table caption="Agency staff">
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Email</Th>
                                <Th>Roles</Th>
                                <Th>Reach</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {staff.map((person) => (
                                <tr key={person.id}>
                                    <Td>{person.name}</Td>
                                    <Td className="text-muted-foreground">{person.email}</Td>
                                    <Td className="text-muted-foreground">
                                        {person.roles.join(', ') || '—'}
                                    </Td>
                                    <Td>
                                        {person.reachesEveryClient ? (
                                            <Badge tone="warning">Every client</Badge>
                                        ) : (
                                            <Badge tone="neutral">Assigned clients only</Badge>
                                        )}
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

function AssignPlanForm({ agencyId, templates }: { agencyId: string; templates: Plan[] }) {
    const { data, setData, post, processing, errors } = useForm({
        plan: templates[0]?.id ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('admin.agencies.pricing', { agency: agencyId }), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-t border-border pt-4">
            <Field
                label="Assign a schedule"
                hint="A copy is made for this agency, so changing the template later does not change what they pay."
                error={errors.plan}
                className="min-w-64"
            >
                {(field) => (
                    <Select
                        {...field}
                        value={data.plan}
                        onChange={(event) => setData('plan', event.target.value)}
                    >
                        {templates.map((template) => (
                            <option key={template.id} value={template.id}>
                                {template.name}
                                {template.isDefault ? ' (default)' : ''}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <Button type="submit" loading={processing}>
                Assign
            </Button>
        </form>
    );
}
