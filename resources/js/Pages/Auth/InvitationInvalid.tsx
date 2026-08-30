import { Head, Link } from '@inertiajs/react';
import { Button } from '@/Components/UI/Button';
import AuthLayout from '@/Layouts/AuthLayout';

export default function InvitationInvalid() {
    return (
        <AuthLayout
            title="This invitation is no longer valid"
            description="It may have expired, been revoked, or already been used."
        >
            <Head title="Invitation not valid" />

            <p className="text-sm text-muted-foreground">
                Ask whoever invited you to send a new invitation. If you already accepted it, sign in instead.
            </p>

            <Button asChild className="mt-6 w-full">
                <Link href={route('login')}>Go to sign in</Link>
            </Button>
        </AuthLayout>
    );
}
