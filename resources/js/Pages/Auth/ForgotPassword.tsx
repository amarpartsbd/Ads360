import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import AuthLayout from '@/Layouts/AuthLayout';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.email'));
    };

    return (
        <AuthLayout
            title="Reset your password"
            description="We will email you a link to choose a new password."
        >
            <Head title="Reset your password" />

            {status ? (
                <div className="mb-4">
                    <Alert tone="success">{status}</Alert>
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

                <Button type="submit" loading={processing} className="w-full">
                    Email reset link
                </Button>
            </form>

            <p className="mt-6 text-center text-sm">
                <Link href={route('login')} className="text-primary underline-offset-4 hover:underline">
                    Back to sign in
                </Link>
            </p>
        </AuthLayout>
    );
}
