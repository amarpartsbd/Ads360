import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { usePageError } from '@/Hooks/usePageError';
import AuthLayout from '@/Layouts/AuthLayout';

export default function AcceptInvitation({
    token,
    organizationName,
    roleName,
    email,
    suggestedName,
    expiresAt,
    hasAccount,
}: {
    token: string;
    organizationName: string;
    roleName: string;
    email: string;
    suggestedName: string | null;
    expiresAt: string;
    hasAccount: boolean;
}) {
    // The token is in the URL, not the form, so its errors arrive separately.
    const tokenError = usePageError('token');

    const { data, setData, post, processing, errors, reset } = useForm({
        name: suggestedName ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('invitations.accept', token), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title={`Join ${organizationName}`} description={`You have been invited as ${roleName}.`}>
            <Head title={`Join ${organizationName}`} />

            <dl className="mb-6 space-y-1 rounded-[var(--radius-control)] bg-surface-muted px-4 py-3 text-sm">
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Email</dt>
                    <dd className="truncate font-medium">{email}</dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Role</dt>
                    <dd className="font-medium">{roleName}</dd>
                </div>
                <div className="flex justify-between gap-4">
                    <dt className="text-muted-foreground">Expires</dt>
                    <dd className="font-medium">{new Date(expiresAt).toLocaleDateString()}</dd>
                </div>
            </dl>

            <form onSubmit={submit} className="space-y-4">
                {!hasAccount ? (
                    <>
                        <Field label="Your name" error={errors.name} required>
                            {(props) => (
                                <Input
                                    {...props}
                                    autoComplete="name"
                                    autoFocus
                                    value={data.name}
                                    onChange={(event) => setData('name', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field
                            label="Choose a password"
                            error={errors.password}
                            required
                            hint="At least 12 characters, with upper and lower case, a number and a symbol."
                        >
                            {(props) => (
                                <Input
                                    {...props}
                                    type="password"
                                    autoComplete="new-password"
                                    value={data.password}
                                    onChange={(event) => setData('password', event.target.value)}
                                />
                            )}
                        </Field>

                        <Field label="Confirm password" error={errors.password_confirmation} required>
                            {(props) => (
                                <Input
                                    {...props}
                                    type="password"
                                    autoComplete="new-password"
                                    value={data.password_confirmation}
                                    onChange={(event) => setData('password_confirmation', event.target.value)}
                                />
                            )}
                        </Field>
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        You already have an account with this email address. Accepting will add this
                        organization to it.
                    </p>
                )}

                {tokenError ? (
                    <p role="alert" className="text-sm text-danger">
                        {tokenError}
                    </p>
                ) : null}

                <Button type="submit" loading={processing} className="w-full">
                    Accept invitation
                </Button>
            </form>
        </AuthLayout>
    );
}
