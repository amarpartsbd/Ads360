import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import AuthLayout from '@/Layouts/AuthLayout';

export default function Login({ canResetPassword, status }: { canResetPassword: boolean; status?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout title="Sign in" description="Access your advertising workspace.">
            <Head title="Sign in" />

            {status ? (
                <div className="mb-4">
                    <Alert tone="info">{status}</Alert>
                </div>
            ) : null}

            <form onSubmit={submit} className="space-y-4">
                <Field label="Email address" error={errors.email} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="email"
                            name="email"
                            autoComplete="username"
                            autoFocus
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                        />
                    )}
                </Field>

                <Field label="Password" error={errors.password} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="password"
                            name="password"
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    )}
                </Field>

                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="size-4 rounded border-input"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                        />
                        Remember me
                    </label>

                    {canResetPassword ? (
                        <Link
                            href={route('password.request')}
                            className="text-sm text-primary underline-offset-4 hover:underline"
                        >
                            Forgot password?
                        </Link>
                    ) : null}
                </div>

                <Button type="submit" loading={processing} className="w-full">
                    Sign in
                </Button>
            </form>

            <p className="mt-6 text-center text-sm text-muted-foreground">
                New to the platform?{' '}
                <Link href={route('register')} className="text-primary underline-offset-4 hover:underline">
                    Create an account
                </Link>
            </p>
        </AuthLayout>
    );
}
