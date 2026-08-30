import { CheckCircle2, Circle, Lock } from 'lucide-react';
import { Alert } from '@/Components/UI/Alert';
import { Badge } from '@/Components/UI/Badge';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import ClientLayout from '@/Layouts/ClientLayout';

interface OnboardingStep {
    key: string;
    label: string;
    complete: boolean;
    available: boolean;
}

export default function Dashboard({
    organization,
    onboarding,
}: {
    organization: { name: string; status: string; statusLabel: string; currency: string };
    onboarding: { verified: boolean; steps: OnboardingStep[] };
}) {
    return (
        <ClientLayout title="Dashboard" description={`Overview for ${organization.name}.`}>
            {!onboarding.verified ? (
                <Alert tone="warning" title="Business verification pending">
                    Campaign publishing and wallet funding open once your business documents have been
                    reviewed.
                </Alert>
            ) : null}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard label="Workspace" value={organization.name} />
                <MetricCard
                    label="Account status"
                    value={organization.statusLabel}
                    badge={
                        <Badge
                            tone={onboarding.verified ? 'success' : 'warning'}
                            icon={
                                onboarding.verified ? (
                                    <CheckCircle2 className="size-3" aria-hidden="true" />
                                ) : (
                                    <Circle className="size-3" aria-hidden="true" />
                                )
                            }
                        >
                            {organization.statusLabel}
                        </Badge>
                    }
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

                                <span className={step.complete ? 'text-muted-foreground line-through' : ''}>
                                    {step.label}
                                </span>

                                {step.complete ? (
                                    <span className="sr-only">Completed</span>
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
    value: string;
    note?: string;
    badge?: React.ReactNode;
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
