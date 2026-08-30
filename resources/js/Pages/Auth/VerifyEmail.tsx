import { Head, router, useForm } from '@inertiajs/react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import AuthLayout from '@/Layouts/AuthLayout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    return (
        <AuthLayout
            title="Verify your email"
            description="We sent a verification link to your business email address."
        >
            <Head title="Verify your email" />

            {status === 'verification-link-sent' ? (
                <div className="mb-4">
                    <Alert tone="success">A new verification link has been sent.</Alert>
                </div>
            ) : null}

            <p className="text-sm text-muted-foreground">
                Click the link in that email to activate your account. If it has not arrived, we can send
                another.
            </p>

            <div className="mt-6 flex flex-wrap items-center gap-3">
                <Button onClick={() => post(route('verification.send'))} loading={processing}>
                    Resend verification email
                </Button>
                <Button variant="ghost" onClick={() => router.post(route('logout'))}>
                    Sign out
                </Button>
            </div>
        </AuthLayout>
    );
}
