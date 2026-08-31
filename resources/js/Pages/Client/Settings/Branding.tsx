import { useForm } from '@inertiajs/react';
import { Palette } from 'lucide-react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import ClientLayout from '@/Layouts/ClientLayout';

interface BrandingValues {
    name: string | null;
    logo_url: string | null;
    primary_color: string | null;
    support_email: string | null;
    custom_domain: string | null;
}

export default function BrandingSettings({
    branding,
    defaults,
    contrast,
    can,
}: {
    branding: BrandingValues;
    defaults: { name: string; support_email: string };
    contrast: { minimum: number };
    can: { update: boolean };
}) {
    const { data, setData, put, processing, errors } = useForm({
        name: branding.name ?? '',
        logo_url: branding.logo_url ?? '',
        primary_color: branding.primary_color ?? '',
        support_email: branding.support_email ?? '',
        custom_domain: branding.custom_domain ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('client.branding.update'), { preserveScroll: true });
    };

    return (
        <ClientLayout title="Branding" description="How this workspace looks to everyone who uses it.">
            <Alert tone="info" title="Anything left blank uses the platform's own">
                Your workspace falls back to {defaults.name} and {defaults.support_email} for whatever you do
                not set here.
            </Alert>

            <Card>
                <CardHeader
                    title="Your brand"
                    description="Applied to the sidebar, the browser tab and the accent colour throughout."
                />
                <CardBody>
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <Field label="Brand name" hint={`Defaults to ${defaults.name}.`} error={errors.name}>
                            {(field) => (
                                <Input
                                    {...field}
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label="Logo address"
                            hint="A full https:// URL. Shown in place of the name."
                            error={errors.logo_url}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="url"
                                    placeholder="https://"
                                    value={data.logo_url}
                                    onChange={(event) => setData('logo_url', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label="Primary colour"
                            hint={`A hex value such as #2158a7. It must be dark enough for white text to be read on it — at least ${contrast.minimum} to 1.`}
                            error={errors.primary_color}
                        >
                            {(field) => (
                                <div className="flex items-center gap-2">
                                    <Input
                                        {...field}
                                        value={data.primary_color}
                                        placeholder="#2158a7"
                                        onChange={(event) => setData('primary_color', event.target.value)}
                                    />
                                    {/*
                                      A swatch, not the only signal: the value
                                      itself stays readable as text beside it
                                      (spec §74).
                                    */}
                                    <span
                                        aria-hidden="true"
                                        className="size-9 shrink-0 rounded-[var(--radius-control)] border border-border"
                                        style={{ background: data.primary_color || 'transparent' }}
                                    />
                                </div>
                            )}
                        </Field>

                        <Field
                            label="Support address"
                            hint={`Defaults to ${defaults.support_email}.`}
                            error={errors.support_email}
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    type="email"
                                    value={data.support_email}
                                    onChange={(event) => setData('support_email', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label="Your own domain"
                            hint="Point a CNAME at us first — we cannot serve a domain that does not resolve here yet."
                            error={errors.custom_domain}
                            className="sm:col-span-2"
                        >
                            {(field) => (
                                <Input
                                    {...field}
                                    placeholder="ads.example.com"
                                    value={data.custom_domain}
                                    onChange={(event) => setData('custom_domain', event.target.value)}
                                />
                            )}
                        </Field>

                        {can.update ? (
                            <div className="sm:col-span-2">
                                <Button type="submit" loading={processing}>
                                    <Palette className="size-4" aria-hidden="true" />
                                    Save branding
                                </Button>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground sm:col-span-2">
                                You can see this, but changing it needs the branding permission.
                            </p>
                        )}
                    </form>
                </CardBody>
            </Card>
        </ClientLayout>
    );
}
