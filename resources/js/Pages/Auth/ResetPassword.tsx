import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import AuthLayout from '@/Layouts/AuthLayout';

export default function ResetPassword({ email, token }: { email: string; token: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.update'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Choose a new password">
            <Head title="Choose a new password" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Email address" error={errors.email} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="email"
                            name="email"
                            autoComplete="username"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                        />
                    )}
                </Field>

                <Field
                    label="New password"
                    error={errors.password}
                    required
                    hint="At least 12 characters, with upper and lower case, a number and a symbol."
                >
                    {(props) => (
                        <Input
                            {...props}
                            type="password"
                            name="password"
                            autoComplete="new-password"
                            autoFocus
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Confirm new password" error={errors.password_confirmation} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="password"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(event) => setData('password_confirmation', event.target.value)}
                        />
                    )}
                </Field>

                <Button type="submit" loading={processing} className="w-full">
                    Update password
                </Button>
            </form>
        </AuthLayout>
    );
}
