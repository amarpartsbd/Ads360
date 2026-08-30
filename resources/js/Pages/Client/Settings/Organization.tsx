import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import ClientLayout from '@/Layouts/ClientLayout';

interface OrganizationSettings {
    id: string;
    name: string;
    legalName: string | null;
    businessType: string | null;
    website: string | null;
    contactEmail: string | null;
    contactNumber: string | null;
    country: string | null;
    timezone: string;
    currency: string;
    status: string;
    statusLabel: string;
}

export default function OrganizationSettingsPage({
    organization,
    timezones,
    can,
}: {
    organization: OrganizationSettings;
    timezones: string[];
    can: { update: boolean };
}) {
    const { data, setData, put, processing, errors } = useForm({
        name: organization.name,
        website: organization.website ?? '',
        contact_email: organization.contactEmail ?? '',
        contact_number: organization.contactNumber ?? '',
        timezone: organization.timezone,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('client.settings.organization.update'), { preserveScroll: true });
    };

    return (
        <ClientLayout title="Organization" description="How your workspace appears across the platform.">
            <Card>
                <CardHeader
                    title="Account"
                    action={<StatusBadge status={organization.status} label={organization.statusLabel} />}
                />
                <CardBody>
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <Detail label="Legal name" value={organization.legalName} />
                        <Detail label="Country" value={organization.country} />
                        <Detail label="Currency" value={organization.currency} />
                    </dl>
                    <p className="mt-4 text-sm text-muted-foreground">
                        Legal name, country and currency are set from your verified business details. To
                        change them, submit a new verification.
                    </p>
                </CardBody>
            </Card>

            <form onSubmit={submit}>
                <Card>
                    <CardHeader
                        title="Contact details"
                        description="Used for account notices and invoices."
                    />
                    <CardBody className="grid gap-4 sm:grid-cols-2">
                        <Field label="Display name" error={errors.name} required>
                            {(props) => (
                                <Input
                                    {...props}
                                    value={data.name}
                                    disabled={!can.update}
                                    onChange={(event) => setData('name', event.target.value)}
                                />
                            )}
                        </Field>
                        <Field label="Website" error={errors.website}>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="url"
                                    value={data.website}
                                    disabled={!can.update}
                                    onChange={(event) => setData('website', event.target.value)}
                                />
                            )}
                        </Field>
                        <Field label="Contact email" error={errors.contact_email} required>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="email"
                                    value={data.contact_email}
                                    disabled={!can.update}
                                    onChange={(event) => setData('contact_email', event.target.value)}
                                />
                            )}
                        </Field>
                        <Field label="Contact number" error={errors.contact_number} required>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="tel"
                                    value={data.contact_number}
                                    disabled={!can.update}
                                    onChange={(event) => setData('contact_number', event.target.value)}
                                />
                            )}
                        </Field>
                        <Field
                            label="Timezone"
                            error={errors.timezone}
                            required
                            hint="Reporting dates are shown in this timezone."
                        >
                            {(props) => (
                                <Select
                                    {...props}
                                    value={data.timezone}
                                    disabled={!can.update}
                                    onChange={(event) => setData('timezone', event.target.value)}
                                >
                                    {timezones.map((zone) => (
                                        <option key={zone} value={zone}>
                                            {zone}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    </CardBody>
                </Card>

                {can.update ? (
                    <div className="mt-4 flex justify-end">
                        <Button type="submit" loading={processing}>
                            Save changes
                        </Button>
                    </div>
                ) : null}
            </form>
        </ClientLayout>
    );
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">{label}</dt>
            <dd className="mt-0.5 text-sm">{value ?? <span className="text-muted-foreground">—</span>}</dd>
        </div>
    );
}
