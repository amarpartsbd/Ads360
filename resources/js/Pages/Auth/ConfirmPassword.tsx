import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import AuthLayout from '@/Layouts/AuthLayout';

/**
 * Step-up authentication before a privileged action (spec §9).
 */
export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout
            title="Confirm your password"
            description="This action is sensitive. Please confirm your password to continue."
        >
            <Head title="Confirm your password" />

            <form onSubmit={submit} className="space-y-4">
                <Field label="Password" error={errors.password} required>
                    {(props) => (
                        <Input
                            {...props}
                            type="password"
                            name="password"
                            autoComplete="current-password"
                            autoFocus
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                    )}
                </Field>

                <Button type="submit" loading={processing} className="w-full">
                    Confirm
                </Button>
            </form>
        </AuthLayout>
    );
}
