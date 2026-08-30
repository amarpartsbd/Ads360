import { Link } from '@inertiajs/react';
import { Badge } from '@/Components/UI/Badge';
import { StatusBadge } from '@/Components/UI/StatusBadge';
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

interface VerificationSummary {
    id: string;
    status: string;
    statusLabel: string;
    submittedAt: string | null;
    reviewedAt: string | null;
    reviewUrl: string;
}

export default function ClientShow({
    organization,
    verification,
}: {
    organization: OrganizationDetail;
    verification: VerificationSummary | null;
}) {
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
                    action={
                        verification ? (
                            <StatusBadge status={verification.status} label={verification.statusLabel} />
                        ) : null
                    }
                />
                <CardBody>
                    {verification ? (
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <dl className="grid gap-4 text-sm sm:grid-cols-2">
                                <Detail
                                    label="Submitted"
                                    value={
                                        verification.submittedAt
                                            ? new Date(verification.submittedAt).toLocaleString()
                                            : null
                                    }
                                />
                                <Detail
                                    label="Last reviewed"
                                    value={
                                        verification.reviewedAt
                                            ? new Date(verification.reviewedAt).toLocaleString()
                                            : null
                                    }
                                />
                            </dl>
                            <Button asChild variant="outline" size="sm">
                                <Link href={verification.reviewUrl}>Open review</Link>
                            </Button>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            This organization has not started business verification yet.
                        </p>
                    )}
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
