import { Head, Link } from '@inertiajs/react';
import { Button } from '@/Components/UI/Button';

const MESSAGES: Record<number, { title: string; description: string }> = {
    403: {
        title: 'You do not have access to this page',
        description: 'Your account does not hold the permission this page requires.',
    },
    404: { title: 'Page not found', description: 'The page you requested does not exist.' },
    419: { title: 'Your session expired', description: 'Please sign in again to continue.' },
    429: { title: 'Too many requests', description: 'Please wait a moment and try again.' },
    500: {
        title: 'Something went wrong',
        description: 'The problem has been recorded. Please try again shortly.',
    },
    503: { title: 'Down for maintenance', description: 'The platform will be back shortly.' },
};

/**
 * User-facing errors say what happened in plain language; the technical detail
 * stays in the logs (spec §80).
 */
export default function ErrorPage({ status }: { status: number }) {
    const { title, description } = MESSAGES[status] ?? MESSAGES[500]!;

    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-background px-6 text-center">
            <Head title={title} />
            <p className="text-sm font-medium text-muted-foreground">Error {status}</p>
            <div className="max-w-md space-y-2">
                <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Button asChild variant="outline">
                <Link href="/">Return home</Link>
            </Button>
        </div>
    );
}
