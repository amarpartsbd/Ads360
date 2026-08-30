import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Circle, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import ClientLayout from '@/Layouts/ClientLayout';

interface OnboardingStep {
    key: string;
    label: string;
    complete: boolean;
    available: boolean;
    href: string | null;
}

export default function Dashboard({
    organization,
    verification,
    onboarding,
}: {
    organization: { name: string; status: string; statusLabel: string; currency: string };
    verification: {
        status: string;
        statusLabel: string;
        description: string;
        actionable: boolean;
        url: string;
    };
    onboarding: { verified: boolean; steps: OnboardingStep[] };
}) {
    return (
        <ClientLayout title="Dashboard" description={`Overview for ${organization.name}.`}>
            {!onboarding.verified ? (
                <Alert
                    tone={verification.status === 'REJECTED' ? 'danger' : 'warning'}
                    title={`Verification: ${verification.statusLabel}`}
                >
                    <p>{verification.description}</p>
                    {verification.actionable ? (
                        <Button asChild size="sm" variant="outline" className="mt-3">
                            <Link href={verification.url}>
                                Continue verification
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                    ) : null}
                </Alert>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard label="Workspace" value={organization.name} />
                <MetricCard
                    label="Verification"
                    badge={<StatusBadge status={verification.status} label={verification.statusLabel} />}
                />
                <MetricCard label="Currency" value={organization.currency} />
                <MetricCard
                    label="Available balance"
                    value="—"
                    note="Available once the wallet module is enabled"
                />
            </div>

            <Card>
                <CardHeader
                    title="Getting started"
                    description="Complete these steps to launch your first campaign."
                />
                <CardBody>
                    <ol className="space-y-3">
                        {onboarding.steps.map((step) => (
                            <li key={step.key} className="flex items-center gap-3 text-sm">
                                {step.complete ? (
                                    <CheckCircle2
                                        className="size-4 shrink-0 text-success"
                                        aria-hidden="true"
                                    />
                                ) : step.available ? (
                                    <Circle
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <Lock
                                        className="size-4 shrink-0 text-muted-foreground/60"
                                        aria-hidden="true"
                                    />
                                )}

                                <span
                                    className={
                                        step.complete ? 'text-muted-foreground line-through' : undefined
                                    }
                                >
                                    {step.label}
                                </span>

                                {step.complete ? (
                                    <span className="sr-only">Completed</span>
                                ) : step.available && step.href ? (
                                    <Button asChild variant="link" size="sm">
                                        <Link href={step.href}>Start</Link>
                                    </Button>
                                ) : !step.available ? (
                                    <Badge tone="neutral">Coming soon</Badge>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                </CardBody>
            </Card>
        </ClientLayout>
    );
}

function MetricCard({
    label,
    value,
    note,
    badge,
}: {
    label: string;
    value?: string;
    note?: string;
    badge?: ReactNode;
}) {
    return (
        <Card>
            <CardBody className="space-y-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">{label}</p>
                {badge ?? <p className="truncate text-lg font-semibold tracking-tight">{value}</p>}
                {note ? <p className="text-xs text-muted-foreground">{note}</p> : null}
            </CardBody>
        </Card>
    );
}
