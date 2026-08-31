import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Pause, X } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import { Textarea } from '@/Components/UI/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';

interface SerialisedMoney {
    formatted?: string;
    amount?: string;
}

interface AdView {
    id: string;
    name: string;
    headline: string;
    primaryText: string;
    destinationUrl: string;
    creative: string | null;
    creativeId: string | null;
    identity: string | null;
    status: string;
    statusLabel: string;
}

interface AdSetView {
    id: string;
    name: string;
    status: string;
    statusLabel: string;
    bidStrategy: string;
    targeting: Record<string, unknown>;
    ads: AdView[];
}

interface PublicationView {
    operation: string;
    operationLabel: string;
    status: string;
    statusLabel: string;
    provider_reference: string | null;
    attempts: number;
    last_error: string | null;
    completedAt: string | null;
}

interface CampaignView {
    public_id: string;
    name: string;
    client: string | null;
    provider: string;
    objectiveLabel: string;
    status: string;
    statusLabel: string;
    currency: string;
    budget: string;
    budgetTypeLabel: string;
    committedBudget: string;
    chargedTotal: string;
    captured: string;
    reportedSpend: string;
    startsAt: string | null;
    endsAt: string | null;
    submittedAt: string | null;
    reviewNotes: string | null;
    lastError: string | null;
    costs: { fees?: { label: string; amount: SerialisedMoney }[]; total?: SerialisedMoney };
    adAccount: { id: string; name: string; health: string } | null;
    adSets: AdSetView[];
    publications: PublicationView[];
}

export default function AdminCampaignShow({
    campaign,
    can,
    isOwnSubmission,
    needsSecondApprover,
}: {
    campaign: CampaignView;
    can: { approve: boolean; reject: boolean; pause: boolean };
    isOwnSubmission: boolean;
    needsSecondApprover: boolean;
}) {
    const inReview = campaign.status === 'PENDING_REVIEW';

    return (
        <AdminLayout
            title={campaign.name}
            description={`${campaign.client ?? 'Unknown client'} · ${campaign.objectiveLabel}`}
            actions={
                <div className="flex gap-2">
                    <Button asChild variant="ghost">
                        <Link href={route('admin.campaigns.index')}>
                            <ArrowLeft aria-hidden="true" />
                            Back
                        </Link>
                    </Button>
                    {can.pause && campaign.status === 'ACTIVE' ? (
                        <Button
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    route('admin.campaigns.pause', campaign.public_id),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Pause aria-hidden="true" />
                            Pause
                        </Button>
                    ) : null}
                </div>
            }
        >
            {isOwnSubmission && inReview ? (
                <Alert tone="warning" title="You submitted this campaign">
                    Someone else has to review it. A campaign cannot be approved by the person who sent it.
                </Alert>
            ) : null}

            {needsSecondApprover ? (
                <Alert tone="info" title="This budget needs two approvers">
                    Above the configured threshold a single approval is not enough.
                </Alert>
            ) : null}

            {campaign.lastError ? (
                <Alert tone="danger" title="Publishing failed">
                    {campaign.lastError} The budget is still held, so this can be retried.
                </Alert>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader title="Campaign" />
                    <CardBody className="space-y-2 text-sm">
                        <Row label="Status">
                            <StatusBadge status={campaign.status} label={campaign.statusLabel} />
                        </Row>
                        <Row label="Budget">
                            {campaign.budget} ({campaign.budgetTypeLabel})
                        </Row>
                        <Row label="Whole run">{campaign.committedBudget}</Row>
                        <Row label="Starts">
                            {campaign.startsAt ? new Date(campaign.startsAt).toLocaleString() : '—'}
                        </Row>
                        <Row label="Ends">
                            {campaign.endsAt ? new Date(campaign.endsAt).toLocaleString() : '—'}
                        </Row>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Money" description="Frozen at submission." />
                    <CardBody className="space-y-2 text-sm">
                        {(campaign.costs.fees ?? []).map((fee, index) => (
                            <Row key={`${fee.label}-${index}`} label={fee.label}>
                                {fee.amount.formatted ?? fee.amount.amount ?? '—'}
                            </Row>
                        ))}
                        <Row label="Total held">{campaign.chargedTotal}</Row>
                        <Row label="Captured">{campaign.captured}</Row>
                        <Row label="Provider reports">{campaign.reportedSpend}</Row>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Ad account" description="Assigned by allocation at approval." />
                    <CardBody className="space-y-2 text-sm">
                        {campaign.adAccount === null ? (
                            <p className="text-muted-foreground">Not allocated yet.</p>
                        ) : (
                            <>
                                <Row label="Account">
                                    <Link
                                        href={route('admin.ad-accounts.show', campaign.adAccount.id)}
                                        className="text-primary underline-offset-4 hover:underline"
                                    >
                                        {campaign.adAccount.name}
                                    </Link>
                                </Row>
                                <Row label="Health">{campaign.adAccount.health}</Row>
                            </>
                        )}
                    </CardBody>
                </Card>
            </div>

            {campaign.adSets.map((adSet) => (
                <Card key={adSet.id}>
                    <CardHeader
                        title={adSet.name}
                        description={adSet.bidStrategy}
                        action={<StatusBadge status={adSet.status} label={adSet.statusLabel} />}
                    />
                    <CardBody className="space-y-3">
                        <details className="text-sm">
                            <summary className="cursor-pointer text-muted-foreground">Targeting</summary>
                            <pre className="mt-2 overflow-x-auto rounded-[var(--radius-control)] bg-surface-muted p-3 text-xs">
                                {JSON.stringify(adSet.targeting, null, 2)}
                            </pre>
                        </details>

                        {adSet.ads.map((ad) => (
                            <div
                                key={ad.id}
                                className="space-y-1 rounded-[var(--radius-control)] border border-border p-3 text-sm"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <p className="font-medium">{ad.headline}</p>
                                    <StatusBadge status={ad.status} label={ad.statusLabel} />
                                </div>
                                <p className="text-muted-foreground">{ad.primaryText}</p>
                                <p className="text-xs text-muted-foreground">
                                    Appears as {ad.identity ?? 'no page'} ·{' '}
                                    {ad.creativeId ? (
                                        <a
                                            href={route('admin.creatives.download', ad.creativeId)}
                                            className="text-primary underline-offset-4 hover:underline"
                                        >
                                            {ad.creative}
                                        </a>
                                    ) : (
                                        'no image'
                                    )}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">{ad.destinationUrl}</p>
                            </div>
                        ))}
                    </CardBody>
                </Card>
            ))}

            {campaign.publications.length > 0 ? (
                <Card>
                    <CardHeader
                        title="Publishing trail"
                        description="Every request sent to the provider, and what came back."
                    />
                    <Table caption="Publication attempts">
                        <thead>
                            <tr>
                                <Th>Operation</Th>
                                <Th>Result</Th>
                                <Th>Provider reference</Th>
                                <Th className="text-right">Attempts</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {campaign.publications.map((publication, index) => (
                                <tr key={`${publication.operation}-${index}`}>
                                    <Td>{publication.operationLabel}</Td>
                                    <Td>
                                        <StatusBadge
                                            status={publication.status}
                                            label={publication.statusLabel}
                                        />
                                        {publication.last_error ? (
                                            <p className="text-xs text-muted-foreground">
                                                {publication.last_error}
                                            </p>
                                        ) : null}
                                    </Td>
                                    <Td className="font-mono text-xs">
                                        {publication.provider_reference ?? '—'}
                                    </Td>
                                    <Td className="text-right tabular-nums">{publication.attempts}</Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            ) : null}

            {inReview && (can.approve || can.reject) ? <DecisionForm campaign={campaign} can={can} /> : null}
        </AdminLayout>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium tabular-nums">{children}</span>
        </div>
    );
}

function DecisionForm({
    campaign,
    can,
}: {
    campaign: CampaignView;
    can: { approve: boolean; reject: boolean };
}) {
    const { data, setData, processing, errors } = useForm({ reason: '' });

    const approve = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            route('admin.campaigns.approve', campaign.public_id),
            { notes: data.reason },
            { preserveScroll: true },
        );
    };

    const decline = (allowChanges: boolean) => {
        router.post(
            route('admin.campaigns.reject', campaign.public_id),
            { reason: data.reason, allow_changes: allowChanges },
            { preserveScroll: true },
        );
    };

    return (
        <Card>
            <CardHeader
                title="Decision"
                description="Approving holds the client's budget and assigns an ad account. Both happen together or not at all."
            />
            <CardBody>
                <form onSubmit={approve} className="space-y-4">
                    <Field
                        label="Notes for the client"
                        hint="Required when declining or asking for changes. Written for them to act on."
                        error={errors.reason}
                    >
                        {(field) => (
                            <Textarea
                                {...field}
                                rows={3}
                                value={data.reason}
                                onChange={(event) => setData('reason', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="flex flex-wrap gap-2">
                        {can.approve ? (
                            <Button type="submit" loading={processing}>
                                <Check aria-hidden="true" />
                                Approve
                            </Button>
                        ) : null}

                        {can.reject ? (
                            <>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={processing}
                                    onClick={() => decline(true)}
                                >
                                    Ask for changes
                                </Button>
                                <Button
                                    type="button"
                                    variant="danger"
                                    disabled={processing}
                                    onClick={() => decline(false)}
                                >
                                    <X aria-hidden="true" />
                                    Decline
                                </Button>
                            </>
                        ) : null}
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
