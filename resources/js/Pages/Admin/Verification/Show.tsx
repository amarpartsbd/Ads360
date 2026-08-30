import { Link, useForm } from '@inertiajs/react';
import { FileText, Lock } from 'lucide-react';
import { useMemo, type FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import { Textarea } from '@/Components/UI/Textarea';
import AdminLayout from '@/Layouts/AdminLayout';

interface Document {
    id: string;
    type: string;
    typeLabel: string;
    filename: string;
    size: string;
    isImage: boolean;
    status: string;
    statusLabel: string;
    reviewNote: string | null;
    uploadedAt: string | null;
    downloadUrl: string;
}

interface HistoryEntry {
    id: string;
    decision: string;
    decisionLabel: string;
    fromStatus: string;
    toStatus: string;
    reviewer: string;
    internalNote: string | null;
    clientMessage: string | null;
    at: string | null;
}

interface Profile {
    id: string;
    status: string;
    statusLabel: string;
    submittedAt: string | null;
    reviewedAt: string | null;
    clientMessage: string | null;
    organization: {
        id: string;
        name: string;
        status: string;
        tenant: string;
        tenantType: string;
    };
    business: {
        legalName: string;
        tradingName: string | null;
        type: string;
        website: string | null;
        facebookPage: string | null;
        contactNumber: string;
        businessEmail: string;
        address: string[];
        tradeLicenseNumber: string | null;
        tin: string | null;
        binVatNumber: string | null;
        expectedMonthlySpend: string | null;
        advertisingCategory: string | null;
    };
    authorizedPerson: {
        name: string;
        designation: string;
        email: string;
        phone: string;
    };
}

export default function VerificationShow({
    profile,
    documents,
    history,
    availableDecisions,
    can,
}: {
    profile: Profile;
    documents: Document[];
    history: HistoryEntry[];
    availableDecisions: { value: string; label: string; requiresMessage: boolean }[];
    can: { review: boolean; suspend: boolean };
}) {
    return (
        <AdminLayout
            title={profile.organization.name}
            description={`Verification submitted by ${profile.organization.tenant}.`}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href={route('admin.verification.index')}>Back to queue</Link>
                </Button>
            }
        >
            <div className="grid gap-4 lg:grid-cols-3">
                <div className="flex flex-col gap-4 lg:col-span-2">
                    <BusinessCard profile={profile} />
                    <DocumentsCard documents={documents} />
                    <HistoryCard history={history} />
                </div>

                <div className="flex flex-col gap-4">
                    <StatusCard profile={profile} />
                    {can.review || can.suspend ? (
                        <DecisionCard
                            profile={profile}
                            documents={documents}
                            decisions={availableDecisions}
                        />
                    ) : null}
                </div>
            </div>
        </AdminLayout>
    );
}

function StatusCard({ profile }: { profile: Profile }) {
    return (
        <Card>
            <CardHeader
                title="Status"
                action={<StatusBadge status={profile.status} label={profile.statusLabel} />}
            />
            <CardBody className="space-y-2 text-sm">
                <Row label="Organization status" value={profile.organization.status} />
                <Row
                    label="Tenant"
                    value={`${profile.organization.tenant} (${profile.organization.tenantType})`}
                />
                <Row
                    label="Submitted"
                    value={profile.submittedAt ? new Date(profile.submittedAt).toLocaleString() : '—'}
                />
                <Row
                    label="Last reviewed"
                    value={profile.reviewedAt ? new Date(profile.reviewedAt).toLocaleString() : '—'}
                />
            </CardBody>
        </Card>
    );
}

function BusinessCard({ profile }: { profile: Profile }) {
    const { business, authorizedPerson } = profile;

    return (
        <Card>
            <CardHeader title="Declared business details" />
            <CardBody className="grid gap-4 sm:grid-cols-2">
                <Detail label="Legal name" value={business.legalName} />
                <Detail label="Trading name" value={business.tradingName} />
                <Detail label="Business type" value={business.type} />
                <Detail label="Advertising category" value={business.advertisingCategory} />
                <Detail label="Website" value={business.website} />
                <Detail label="Facebook page" value={business.facebookPage} />
                <Detail label="Business email" value={business.businessEmail} />
                <Detail label="Contact number" value={business.contactNumber} />
                <Detail label="Address" value={business.address.join(', ')} className="sm:col-span-2" />
                <Detail label="Trade licence" value={business.tradeLicenseNumber} />
                <Detail label="TIN" value={business.tin} />
                <Detail label="BIN / VAT" value={business.binVatNumber} />
                <Detail label="Expected monthly spend" value={business.expectedMonthlySpend} />
                <Detail
                    label="Authorised person"
                    value={`${authorizedPerson.name} — ${authorizedPerson.designation}`}
                    className="sm:col-span-2"
                />
                <Detail label="Their email" value={authorizedPerson.email} />
                <Detail label="Their phone" value={authorizedPerson.phone} />
            </CardBody>
        </Card>
    );
}

function DocumentsCard({ documents }: { documents: Document[] }) {
    return (
        <Card>
            <CardHeader
                title="Documents"
                description="Opens in a new tab. Every view is recorded in the audit log."
            />
            {documents.length === 0 ? (
                <EmptyState icon={FileText} title="No documents attached" />
            ) : (
                <Table caption="Submitted verification documents">
                    <thead>
                        <tr>
                            <Th>Type</Th>
                            <Th>File</Th>
                            <Th>Uploaded</Th>
                            <Th>Status</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {documents.map((document) => (
                            <tr key={document.id}>
                                <Td className="font-medium">{document.typeLabel}</Td>
                                <Td>
                                    <a
                                        href={document.downloadUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-primary underline-offset-4 hover:underline"
                                    >
                                        {document.filename}
                                    </a>
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        {document.size}
                                    </span>
                                </Td>
                                <Td className="text-muted-foreground">
                                    {document.uploadedAt
                                        ? new Date(document.uploadedAt).toLocaleDateString()
                                        : '—'}
                                </Td>
                                <Td>
                                    <StatusBadge status={document.status} label={document.statusLabel} />
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}

function HistoryCard({ history }: { history: HistoryEntry[] }) {
    return (
        <Card>
            <CardHeader
                title="Review history"
                description="Internal notes are visible to platform staff only."
            />
            {history.length === 0 ? (
                <EmptyState icon={Lock} title="No decisions recorded yet" />
            ) : (
                <CardBody>
                    <ol className="space-y-4">
                        {history.map((entry) => (
                            <li key={entry.id} className="border-l-2 border-border pl-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium">{entry.decisionLabel}</span>
                                    <span className="text-xs text-muted-foreground">
                                        {entry.fromStatus} → {entry.toStatus}
                                    </span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {entry.reviewer}
                                    {entry.at ? ` · ${new Date(entry.at).toLocaleString()}` : ''}
                                </p>
                                {entry.clientMessage ? (
                                    <p className="mt-2 text-sm">
                                        <span className="text-muted-foreground">To client: </span>
                                        {entry.clientMessage}
                                    </p>
                                ) : null}
                                {entry.internalNote ? (
                                    <p className="mt-1 rounded bg-surface-muted px-2 py-1 text-sm">
                                        <span className="text-muted-foreground">Internal: </span>
                                        {entry.internalNote}
                                    </p>
                                ) : null}
                            </li>
                        ))}
                    </ol>
                </CardBody>
            )}
        </Card>
    );
}

function DecisionCard({
    profile,
    documents,
    decisions,
}: {
    profile: Profile;
    documents: Document[];
    decisions: { value: string; label: string; requiresMessage: boolean }[];
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        decision: decisions[0]?.value ?? '',
        client_message: '',
        internal_note: '',
        documents: [] as string[],
    });

    const selected = useMemo(
        () => decisions.find((decision) => decision.value === data.decision),
        [decisions, data.decision],
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('admin.verification.review', profile.id), {
            preserveScroll: true,
            onSuccess: () => reset('client_message', 'internal_note', 'documents'),
        });
    };

    if (decisions.length === 0) {
        return (
            <Card>
                <CardHeader title="Decision" />
                <CardBody>
                    <p className="text-sm text-muted-foreground">
                        No further action is available while this submission is{' '}
                        {profile.statusLabel.toLowerCase()}.
                    </p>
                </CardBody>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader title="Record a decision" />
            <CardBody>
                <form onSubmit={submit} className="space-y-4">
                    <Field label="Decision" error={errors.decision} required>
                        {(props) => (
                            <Select
                                {...props}
                                value={data.decision}
                                onChange={(event) => setData('decision', event.target.value)}
                            >
                                {decisions.map((decision) => (
                                    <option key={decision.value} value={decision.value}>
                                        {decision.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field
                        label="Message to the client"
                        error={errors.client_message}
                        required={selected?.requiresMessage}
                        hint={
                            selected?.requiresMessage
                                ? 'The client will see this. Say precisely what they need to do.'
                                : 'Optional. The client will see this.'
                        }
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                value={data.client_message}
                                onChange={(event) => setData('client_message', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Internal note"
                        error={errors.internal_note}
                        hint="Platform staff only. Never shown to the client."
                    >
                        {(props) => (
                            <Textarea
                                {...props}
                                rows={3}
                                value={data.internal_note}
                                onChange={(event) => setData('internal_note', event.target.value)}
                            />
                        )}
                    </Field>

                    {documents.length > 0 ? (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">Documents this refers to</legend>
                            {documents.map((document) => (
                                <label key={document.id} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="size-4 rounded border-input"
                                        checked={data.documents.includes(document.id)}
                                        onChange={(event) =>
                                            setData(
                                                'documents',
                                                event.target.checked
                                                    ? [...data.documents, document.id]
                                                    : data.documents.filter((id) => id !== document.id),
                                            )
                                        }
                                    />
                                    {document.typeLabel}
                                </label>
                            ))}
                        </fieldset>
                    ) : null}

                    {selected?.value === 'SUSPENDED' ? (
                        <Alert tone="warning" title="This restricts the account">
                            Suspending withdraws verification and blocks the organization from transacting.
                        </Alert>
                    ) : null}

                    <Button type="submit" loading={processing} className="w-full">
                        Record decision
                    </Button>
                </form>
            </CardBody>
        </Card>
    );
}

function Detail({ label, value, className }: { label: string; value: string | null; className?: string }) {
    return (
        <div className={className}>
            <dt className="text-xs tracking-wide text-muted-foreground uppercase">{label}</dt>
            <dd className="mt-0.5 text-sm">{value || <span className="text-muted-foreground">—</span>}</dd>
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </div>
    );
}
