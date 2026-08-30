import { Link } from '@inertiajs/react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import AdminLayout from '@/Layouts/AdminLayout';

interface OrganizationDetail {
    id: string;
    name: string;
    legalName: string | null;
    status: string;
    statusLabel: string;
    country: string | null;
    timezone: string;
    currency: string;
    contactEmail: string | null;
    contactNumber: string | null;
    website: string | null;
    createdAt: string | null;
    tenant: { name: string; type: string; status: string };
}

export default function ClientShow({ organization }: { organization: OrganizationDetail }) {
    return (
        <AdminLayout
            title={organization.name}
            description={`Managed under ${organization.tenant.name}.`}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href={route('admin.clients.index')}>Back to clients</Link>
                </Button>
            }
        >
            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        title="Organization"
                        action={
                            <Badge tone={organization.status === 'ACTIVE' ? 'success' : 'warning'}>
                                {organization.statusLabel}
                            </Badge>
                        }
                    />
                    <CardBody>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <Detail label="Trading name" value={organization.name} />
                            <Detail label="Legal name" value={organization.legalName} />
                            <Detail label="Country" value={organization.country} />
                            <Detail label="Timezone" value={organization.timezone} />
                            <Detail label="Currency" value={organization.currency} />
                            <Detail
                                label="Registered"
                                value={
                                    organization.createdAt
                                        ? new Date(organization.createdAt).toLocaleDateString()
                                        : null
                                }
                            />
                        </dl>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Contact" />
                    <CardBody>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            <Detail label="Email" value={organization.contactEmail} />
                            <Detail label="Phone" value={organization.contactNumber} />
                            <Detail label="Website" value={organization.website} />
                            <Detail label="Tenant type" value={organization.tenant.type} />
                        </dl>
                    </CardBody>
                </Card>
            </div>

            <Card>
                <CardHeader
                    title="Business verification"
                    description="Document review and risk scoring arrive with the compliance module."
                />
                <CardBody>
                    <p className="text-sm text-muted-foreground">
                        No verification profile has been submitted for this organization yet.
                    </p>
                </CardBody>
            </Card>
        </AdminLayout>
    );
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">{label}</dt>
            <dd className="mt-0.5">{value ?? <span className="text-muted-foreground">—</span>}</dd>
        </div>
    );
}
