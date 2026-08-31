import { router, useForm } from '@inertiajs/react';
import { Image as ImageIcon, Trash2, Upload } from 'lucide-react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';
import type { Paginated } from '@/Types';

interface CreativeRow {
    id: string;
    name: string;
    type: string;
    typeLabel: string;
    dimensions: string | null;
    sizeLabel: string;
    usedByAds: number;
    uploadedAt: string | null;
    can: { delete: boolean };
}

interface UploadLimits {
    acceptedExtensions: string[];
    maxImageBytes: number;
    maxVideoBytes: number;
    minimumDimension: number;
}

export default function CreativesIndex({
    creatives,
    upload,
    can,
}: {
    creatives: Paginated<CreativeRow>;
    upload: UploadLimits;
    can: { create: boolean };
}) {
    return (
        <ClientLayout
            title="Creative library"
            description="The images and videos your ads can use. Files are private to your organization."
        >
            {can.create ? <UploadForm limits={upload} /> : null}

            <Card>
                <CardHeader title="Your files" />

                {creatives.data.length === 0 ? (
                    <EmptyState
                        icon={ImageIcon}
                        title="Nothing uploaded yet"
                        description="Add an image or video, then use it in an ad."
                    />
                ) : (
                    <Table caption="Creative library">
                        <thead>
                            <tr>
                                <Th>File</Th>
                                <Th>Type</Th>
                                <Th>Size</Th>
                                <Th className="text-right">Used by</Th>
                                <Th className="text-right">Actions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {creatives.data.map((creative) => (
                                <tr key={creative.id}>
                                    <Td>
                                        <a
                                            href={route('client.creatives.download', creative.id)}
                                            className="font-medium text-primary underline-offset-4 hover:underline"
                                        >
                                            {creative.name}
                                        </a>
                                        {creative.dimensions ? (
                                            <p className="text-xs text-muted-foreground">
                                                {creative.dimensions}
                                            </p>
                                        ) : null}
                                    </Td>
                                    <Td>{creative.typeLabel}</Td>
                                    <Td>{creative.sizeLabel}</Td>
                                    <Td className="text-right tabular-nums">
                                        {creative.usedByAds === 0 ? '—' : creative.usedByAds}
                                    </Td>
                                    <Td className="text-right">
                                        {creative.can.delete ? (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        route('client.creatives.destroy', creative.id),
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                <Trash2 aria-hidden="true" />
                                                Remove
                                            </Button>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">In use</span>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </ClientLayout>
    );
}

function UploadForm({ limits }: { limits: UploadLimits }) {
    const { setData, post, processing, errors, reset } = useForm<{ file: File | null; name: string }>({
        file: null,
        name: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(route('client.creatives.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const megabytes = (bytes: number) => Math.round(bytes / 1024 / 1024);

    return (
        <Card>
            <CardHeader
                title="Add a file"
                description={`Accepted: ${limits.acceptedExtensions.join(', ')}. Images up to ${megabytes(limits.maxImageBytes)} MB and at least ${limits.minimumDimension}px on each side; video up to ${megabytes(limits.maxVideoBytes)} MB.`}
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="File" error={errors.file} required>
                        {(field) => (
                            <Input
                                {...field}
                                type="file"
                                accept={limits.acceptedExtensions.map((ext) => `.${ext}`).join(',')}
                                onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                            />
                        )}
                    </Field>

                    <Field label="Name" hint="Optional. Defaults to the filename." error={errors.name}>
                        {(field) => (
                            <Input {...field} onChange={(event) => setData('name', event.target.value)} />
                        )}
                    </Field>

                    <div className="sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            <Upload aria-hidden="true" />
                            Upload
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
