import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Button } from '@/Components/UI/Button';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import AuthLayout from '@/Layouts/AuthLayout';

export default function TwoFactorChallenge() {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('two-factor.login'), { onFinish: () => reset('code', 'recovery_code') });
    };

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={
                useRecoveryCode
                    ? 'Enter one of your recovery codes.'
                    : 'Enter the six-digit code from your authenticator app.'
            }
        >
            <Head title="Two-factor authentication" />

            <form onSubmit={submit} className="space-y-4">
                {useRecoveryCode ? (
                    <Field label="Recovery code" error={errors.recovery_code} required>
                        {(props) => (
                            <Input
                                {...props}
                                name="recovery_code"
                                autoComplete="one-time-code"
                                autoFocus
                                value={data.recovery_code}
                                onChange={(event) => setData('recovery_code', event.target.value)}
                            />
                        )}
                    </Field>
                ) : (
                    <Field label="Authentication code" error={errors.code} required>
                        {(props) => (
                            <Input
                                {...props}
                                name="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={6}
                                autoFocus
                                value={data.code}
                                onChange={(event) => setData('code', event.target.value)}
                            />
                        )}
                    </Field>
                )}

                <Button type="submit" loading={processing} className="w-full">
                    Verify
                </Button>

                <Button
                    type="button"
                    variant="link"
                    className="w-full"
                    onClick={() => {
                        setUseRecoveryCode((current) => !current);
                        reset('code', 'recovery_code');
                    }}
                >
                    {useRecoveryCode ? 'Use an authenticator code instead' : 'Use a recovery code instead'}
                </Button>
            </form>
        </AuthLayout>
    );
}
