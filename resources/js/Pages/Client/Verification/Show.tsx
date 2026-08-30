import { router, useForm } from '@inertiajs/react';
import { FileText, Trash2, Upload } from 'lucide-react';
import { useRef, type FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import { usePageError } from '@/Hooks/usePageError';
import ClientLayout from '@/Layouts/ClientLayout';

interface ProfileFields {
    legal_business_name: string | null;
    trading_name: string | null;
    business_type: string | null;
    website: string | null;
    facebook_page: string | null;
    contact_number: string | null;
    business_email: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    postal_code: string | null;
    country: string | null;
    authorized_person_name: string | null;
    authorized_person_designation: string | null;
    authorized_person_email: string | null;
    authorized_person_phone: string | null;
    trade_license_number: string | null;
    tin: string | null;
    bin_vat_number: string | null;
    expected_monthly_spend: string | null;
    advertising_category: string | null;
}

interface Profile {
    status: string;
    statusLabel: string;
    statusDescription: string;
    editable: boolean;
    submittedAt: string | null;
    reviewedAt: string | null;
    reviewerMessage: string | null;
    fields: ProfileFields;
}

interface Document {
    id: string;
    type: string;
    typeLabel: string;
    filename: string;
    size: string;
    status: string;
    statusLabel: string;
    reviewNote: string | null;
    uploadedAt: string | null;
    downloadUrl: string;
}

interface DocumentTypeOption {
    value: string;
    label: string;
    required: boolean;
}

const COUNTRIES = [
    ['BD', 'Bangladesh'],
    ['IN', 'India'],
    ['MY', 'Malaysia'],
    ['SG', 'Singapore'],
    ['AE', 'United Arab Emirates'],
    ['GB', 'United Kingdom'],
    ['US', 'United States'],
] as const;

export default function VerificationShow({
    profile,
    documents,
    missingDocuments,
    documentTypes,
    upload,
    can,
}: {
    profile: Profile;
    documents: Document[];
    missingDocuments: { value: string; label: string }[];
    documentTypes: DocumentTypeOption[];
    upload: { maxBytes: number; acceptedExtensions: string[] };
    can: { update: boolean };
}) {
    const editable = profile.editable && can.update;

    return (
        <ClientLayout
            title="Business verification"
            description="We verify every business before campaigns can be published or funds added."
        >
            <StatusPanel profile={profile} />

            {editable ? (
                <>
                    <DocumentsPanel
                        documents={documents}
                        documentTypes={documentTypes}
                        missing={missingDocuments}
                        upload={upload}
                        editable
                    />
                    <DetailsForm profile={profile} missingCount={missingDocuments.length} />
                </>
            ) : (
                <>
                    <SubmittedDetails profile={profile} />
                    <DocumentsPanel
                        documents={documents}
                        documentTypes={documentTypes}
                        missing={[]}
                        upload={upload}
                        editable={false}
                    />
                </>
            )}
        </ClientLayout>
    );
}

function StatusPanel({ profile }: { profile: Profile }) {
    return (
        <Card>
            <CardHeader
                title="Verification status"
                description={profile.statusDescription}
                action={<StatusBadge status={profile.status} label={profile.statusLabel} />}
            />
            {profile.reviewerMessage ? (
                <CardBody>
                    <Alert
                        tone={profile.status === 'REJECTED' ? 'danger' : 'warning'}
                        title="Message from the review team"
                    >
                        {profile.reviewerMessage}
                    </Alert>
                </CardBody>
            ) : null}
            {profile.submittedAt ? (
                <CardBody className="border-t border-border pt-4 text-sm text-muted-foreground">
                    Submitted {new Date(profile.submittedAt).toLocaleString()}
                    {profile.reviewedAt
                        ? ` · Reviewed ${new Date(profile.reviewedAt).toLocaleString()}`
                        : null}
                </CardBody>
            ) : null}
        </Card>
    );
}

function DocumentsPanel({
    documents,
    documentTypes,
    missing,
    upload,
    editable,
}: {
    documents: Document[];
    documentTypes: DocumentTypeOption[];
    missing: { value: string; label: string }[];
    upload: { maxBytes: number; acceptedExtensions: string[] };
    editable: boolean;
}) {
    const fileInput = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, errors, reset } = useForm<{
        type: string;
        file: File | null;
    }>({ type: documentTypes[0]?.value ?? '', file: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('client.verification.documents.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset('file');
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    };

    const remove = (document: Document) => {
        if (!window.confirm(`Remove ${document.filename}?`)) {
            return;
        }
        router.delete(route('client.verification.documents.destroy', document.id), {
            preserveScroll: true,
        });
    };

    const maxMegabytes = Math.round(upload.maxBytes / 1_048_576);

    return (
        <Card>
            <CardHeader
                title="Supporting documents"
                description={`Accepted formats: ${upload.acceptedExtensions.join(', ')}. Maximum ${maxMegabytes} MB per file.`}
            />

            {missing.length > 0 ? (
                <CardBody>
                    <Alert tone="warning" title="Still needed">
                        {missing.map((item) => item.label).join(', ')}
                    </Alert>
                </CardBody>
            ) : null}

            {documents.length === 0 ? (
                <EmptyState
                    icon={FileText}
                    title="No documents uploaded yet"
                    description="Upload your trade licence and proof of identity for the authorised person."
                />
            ) : (
                <Table caption="Uploaded verification documents">
                    <thead>
                        <tr>
                            <Th>Document</Th>
                            <Th>File</Th>
                            <Th>Status</Th>
                            {editable ? <Th className="text-right">Action</Th> : null}
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
                                <Td>
                                    <StatusBadge status={document.status} label={document.statusLabel} />
                                    {document.reviewNote ? (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {document.reviewNote}
                                        </p>
                                    ) : null}
                                </Td>
                                {editable ? (
                                    <Td className="text-right">
                                        <Button variant="ghost" size="sm" onClick={() => remove(document)}>
                                            <Trash2 aria-hidden="true" />
                                            <span className="sr-only">Remove {document.filename}</span>
                                        </Button>
                                    </Td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}

            {editable ? (
                <CardBody className="border-t border-border">
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                        <Field label="Document type" error={errors.type} className="min-w-56 flex-1">
                            {(props) => (
                                <Select
                                    {...props}
                                    value={data.type}
                                    onChange={(event) => setData('type', event.target.value)}
                                >
                                    {documentTypes.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                            {type.required ? ' (required)' : ''}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>

                        <Field label="File" error={errors.file} className="min-w-64 flex-1">
                            {(props) => (
                                <Input
                                    {...props}
                                    ref={fileInput}
                                    type="file"
                                    accept={upload.acceptedExtensions.map((ext) => `.${ext}`).join(',')}
                                    onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                                    className="file:mr-3 file:rounded file:border-0 file:bg-secondary file:px-3 file:py-1 file:text-sm"
                                />
                            )}
                        </Field>

                        <Button type="submit" loading={processing} disabled={!data.file}>
                            <Upload aria-hidden="true" />
                            Upload
                        </Button>
                    </form>
                </CardBody>
            ) : null}
        </Card>
    );
}

function DetailsForm({ profile, missingCount }: { profile: Profile; missingCount: number }) {
    // Raised when the submission is complete as a form but missing documents.
    const documentsError = usePageError('documents');

    const { data, setData, put, processing, errors } = useForm({
        legal_business_name: profile.fields.legal_business_name ?? '',
        trading_name: profile.fields.trading_name ?? '',
        business_type: profile.fields.business_type ?? '',
        website: profile.fields.website ?? '',
        facebook_page: profile.fields.facebook_page ?? '',
        contact_number: profile.fields.contact_number ?? '',
        business_email: profile.fields.business_email ?? '',
        address_line_1: profile.fields.address_line_1 ?? '',
        address_line_2: profile.fields.address_line_2 ?? '',
        city: profile.fields.city ?? '',
        state: profile.fields.state ?? '',
        postal_code: profile.fields.postal_code ?? '',
        country: profile.fields.country ?? 'BD',
        authorized_person_name: profile.fields.authorized_person_name ?? '',
        authorized_person_designation: profile.fields.authorized_person_designation ?? '',
        authorized_person_email: profile.fields.authorized_person_email ?? '',
        authorized_person_phone: profile.fields.authorized_person_phone ?? '',
        trade_license_number: profile.fields.trade_license_number ?? '',
        tin: profile.fields.tin ?? '',
        bin_vat_number: profile.fields.bin_vat_number ?? '',
        expected_monthly_spend: profile.fields.expected_monthly_spend ?? '',
        advertising_category: profile.fields.advertising_category ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('client.verification.update'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-6">
            <Card>
                <CardHeader title="Business details" description="As they appear on your trade licence." />
                <CardBody className="grid gap-4 sm:grid-cols-2">
                    <Field label="Legal business name" error={errors.legal_business_name} required>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.legal_business_name}
                                onChange={(event) => setData('legal_business_name', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Trading name" error={errors.trading_name}>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.trading_name}
                                onChange={(event) => setData('trading_name', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Business type" error={errors.business_type} required>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.business_type}
                                onChange={(event) => setData('business_type', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Advertising category" error={errors.advertising_category}>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.advertising_category}
                                onChange={(event) => setData('advertising_category', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Website" error={errors.website} hint="Include https://">
                        {(props) => (
                            <Input
                                {...props}
                                type="url"
                                value={data.website}
                                onChange={(event) => setData('website', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Facebook page" error={errors.facebook_page}>
                        {(props) => (
                            <Input
                                {...props}
                                type="url"
                                value={data.facebook_page}
                                onChange={(event) => setData('facebook_page', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Business email" error={errors.business_email} required>
                        {(props) => (
                            <Input
                                {...props}
                                type="email"
                                value={data.business_email}
                                onChange={(event) => setData('business_email', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Contact number" error={errors.contact_number} required>
                        {(props) => (
                            <Input
                                {...props}
                                type="tel"
                                value={data.contact_number}
                                onChange={(event) => setData('contact_number', event.target.value)}
                            />
                        )}
                    </Field>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Registered address" />
                <CardBody className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Address line 1"
                        error={errors.address_line_1}
                        required
                        className="sm:col-span-2"
                    >
                        {(props) => (
                            <Input
                                {...props}
                                value={data.address_line_1}
                                onChange={(event) => setData('address_line_1', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Address line 2" error={errors.address_line_2} className="sm:col-span-2">
                        {(props) => (
                            <Input
                                {...props}
                                value={data.address_line_2}
                                onChange={(event) => setData('address_line_2', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="City" error={errors.city} required>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.city}
                                onChange={(event) => setData('city', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Division / State" error={errors.state}>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.state}
                                onChange={(event) => setData('state', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Postal code" error={errors.postal_code}>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.postal_code}
                                onChange={(event) => setData('postal_code', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Country" error={errors.country} required>
                        {(props) => (
                            <Select
                                {...props}
                                value={data.country}
                                onChange={(event) => setData('country', event.target.value)}
                            >
                                {COUNTRIES.map(([code, name]) => (
                                    <option key={code} value={code}>
                                        {name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>
                </CardBody>
            </Card>

            <Card>
                <CardHeader
                    title="Authorised person"
                    description="Whoever is authorised to act for the business on this account."
                />
                <CardBody className="grid gap-4 sm:grid-cols-2">
                    <Field label="Full name" error={errors.authorized_person_name} required>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.authorized_person_name}
                                onChange={(event) => setData('authorized_person_name', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Designation" error={errors.authorized_person_designation} required>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.authorized_person_designation}
                                onChange={(event) =>
                                    setData('authorized_person_designation', event.target.value)
                                }
                            />
                        )}
                    </Field>
                    <Field label="Email" error={errors.authorized_person_email} required>
                        {(props) => (
                            <Input
                                {...props}
                                type="email"
                                value={data.authorized_person_email}
                                onChange={(event) => setData('authorized_person_email', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="Phone" error={errors.authorized_person_phone} required>
                        {(props) => (
                            <Input
                                {...props}
                                type="tel"
                                value={data.authorized_person_phone}
                                onChange={(event) => setData('authorized_person_phone', event.target.value)}
                            />
                        )}
                    </Field>
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="Registration and spend" />
                <CardBody className="grid gap-4 sm:grid-cols-2">
                    <Field label="Trade licence number" error={errors.trade_license_number}>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.trade_license_number}
                                onChange={(event) => setData('trade_license_number', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="TIN" error={errors.tin}>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.tin}
                                onChange={(event) => setData('tin', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field label="BIN / VAT registration" error={errors.bin_vat_number}>
                        {(props) => (
                            <Input
                                {...props}
                                value={data.bin_vat_number}
                                onChange={(event) => setData('bin_vat_number', event.target.value)}
                            />
                        )}
                    </Field>
                    <Field
                        label="Expected monthly ad spend"
                        error={errors.expected_monthly_spend}
                        hint="Approximate is fine. Used to size your account, not to bill you."
                    >
                        {(props) => (
                            <Input
                                {...props}
                                type="number"
                                min="0"
                                step="0.01"
                                inputMode="decimal"
                                value={data.expected_monthly_spend}
                                onChange={(event) => setData('expected_monthly_spend', event.target.value)}
                            />
                        )}
                    </Field>
                </CardBody>
            </Card>

            {documentsError ? <Alert tone="danger">{documentsError}</Alert> : null}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    {missingCount > 0
                        ? 'Upload the required documents before submitting.'
                        : 'Once submitted, your details are locked while our team reviews them.'}
                </p>
                <Button type="submit" loading={processing}>
                    Submit for review
                </Button>
            </div>
        </form>
    );
}

function SubmittedDetails({ profile }: { profile: Profile }) {
    const rows: [string, string | null][] = [
        ['Legal business name', profile.fields.legal_business_name],
        ['Trading name', profile.fields.trading_name],
        ['Business type', profile.fields.business_type],
        ['Website', profile.fields.website],
        ['Business email', profile.fields.business_email],
        ['Contact number', profile.fields.contact_number],
        ['Authorised person', profile.fields.authorized_person_name],
        ['Designation', profile.fields.authorized_person_designation],
        ['Trade licence number', profile.fields.trade_license_number],
        ['TIN', profile.fields.tin],
        ['BIN / VAT', profile.fields.bin_vat_number],
    ];

    return (
        <Card>
            <CardHeader
                title="Submitted details"
                description="These are locked while your submission is with our review team."
            />
            <CardBody>
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {rows.map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-xs tracking-wide text-muted-foreground uppercase">{label}</dt>
                            <dd className="mt-0.5 text-sm">
                                {value || <span className="text-muted-foreground">—</span>}
                            </dd>
                        </div>
                    ))}
                </dl>
            </CardBody>
        </Card>
    );
}
