import { Head, Link } from '@inertiajs/react';
import { Button } from '@/Components/UI/Button';

export default function Welcome({ platformName }: { platformName: string }) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center gap-8 bg-background px-6 text-center">
            <Head title="Welcome" />

            <div className="max-w-xl space-y-4">
                <h1 className="text-3xl font-semibold tracking-tight">{platformName}</h1>
                <p className="text-muted-foreground">
                    Managed advertising for businesses and agencies. Connect your assets, fund your wallet and
                    launch campaigns through one reviewed workflow.
                </p>
            </div>

            <div className="flex flex-wrap items-center justify-center gap-3">
                <Button asChild>
                    <Link href={route('register')}>Create an account</Link>
                </Button>
                <Button asChild variant="outline">
                    <Link href={route('login')}>Sign in</Link>
                </Button>
            </div>
        </div>
    );
}
