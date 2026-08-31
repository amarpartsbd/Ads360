import { Link, useForm } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import AdminLayout from '@/Layouts/AdminLayout';

interface AgencyRow {
    id: string;
    name: string;
    type: string;
    typeLabel: string;
    status: string;
    statusLabel: string;
    currency: string;
    clients: number;
    plan: string | null;
}

export default function AgenciesIndex({
    agencies,
    moduleEnabled,
    can,
}: {
    agencies: AgencyRow[];
    moduleEnabled: boolean;
    can: { provision: boolean; assignPricing: boolean };
}) {
    const [adding, setAdding] = useState(false);

    return (
        <AdminLayout
            title="Agencies and resellers"
            description="Businesses that manage advertising for other businesses."
            actions={
                can.provision ? (
                    <Button onClick={() => setAdding((open) => !open)}>
                        <Plus className="size-4" aria-hidden="true" />
                        Provision an agency
                    </Button>
                ) : undefined
            }
        >
            {!moduleEnabled ? (
                <Alert tone="warning" title="The agency module is switched off">
                    Existing agencies are listed here, but their client screens are closed and no new agency
                    can be provisioned until FEATURE_AGENCY_MODULE is enabled.
                </Alert>
            ) : null}

            {adding && can.provision ? <ProvisionForm onDone={() => setAdding(false)} /> : null}

            <Card>
                <CardHeader title="All agencies" />

                {agencies.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No agencies yet"
                        description="Provision one to let a business manage clients of its own."
                    />
                ) : (
                    <Table caption="Agencies and resellers">
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Kind</Th>
                                <Th>Status</Th>
                                <Th className="text-right">Clients</Th>
                                <Th>Fee schedule</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {agencies.map((agency) => (
                                <tr key={agency.id}>
                                    <Td>
                                        <Link
                                            href={route('admin.agencies.show', { agency: agency.id })}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {agency.name}
                                        </Link>
                                    </Td>
                                    <Td className="text-muted-foreground">{agency.typeLabel}</Td>
                                    <Td>
                                        <StatusBadge status={agency.status} label={agency.statusLabel} />
                                    </Td>
                                    <Td className="text-right tabular-nums">{agency.clients}</Td>
                                    <Td className="text-muted-foreground">
                                        {/* No plan of their own means the platform default prices them. */}
                                        {agency.plan ?? 'Platform standard'}
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

function ProvisionForm({ onDone }: { onDone: () => void }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        type: 'AGENCY',
        billing_email: '',
        owner_name: '',
        owner_email: '',
        owner_password: '',
        owner_password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('admin.agencies.store'), {
            onSuccess: () => {
                reset();
                onDone();
            },
        });
    };

    return (
        <Card>
            <CardHeader
                title="Provision an agency"
                description="Creates the agency, its own workspace and its owner. The owner reaches every client the agency goes on to manage."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Agency name" error={errors.name} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Kind" error={errors.type} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.type}
                                onChange={(event) => setData('type', event.target.value)}
                            >
                                <option value="AGENCY">Agency</option>
                                <option value="RESELLER">Reseller</option>
                            </Select>
                        )}
                    </Field>

                    <Field label="Billing email" error={errors.billing_email} required>
                        {(field) => (
                            <Input
                                {...field}
                                type="email"
                                value={data.billing_email}
                                onChange={(event) => setData('billing_email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Owner name" error={errors.owner_name} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.owner_name}
                                onChange={(event) => setData('owner_name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Owner email" error={errors.owner_email} required>
                        {(field) => (
                            <Input
                                {...field}
                                type="email"
                                value={data.owner_email}
                                onChange={(event) => setData('owner_email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Owner password"
                        hint="They will be asked to verify their email before signing in."
                        error={errors.owner_password}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                type="password"
                                value={data.owner_password}
                                onChange={(event) => setData('owner_password', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Confirm password" required>
                        {(field) => (
                            <Input
                                {...field}
                                type="password"
                                value={data.owner_password_confirmation}
                                onChange={(event) =>
                                    setData('owner_password_confirmation', event.target.value)
                                }
                            />
                        )}
                    </Field>

                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Provision
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
