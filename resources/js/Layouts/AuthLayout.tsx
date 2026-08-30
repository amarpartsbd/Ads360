import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

export default function AuthLayout({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <div className="flex min-h-screen items-center justify-center bg-background px-4 py-12">
            <Head title={title} />

            <div className="w-full max-w-md space-y-6">
                <div className="space-y-2 text-center">
                    <Link href="/" className="text-lg font-semibold tracking-tight">
                        Ads360
                    </Link>
                    <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                    {description ? <p className="text-sm text-muted-foreground">{description}</p> : null}
                </div>

                <div className="rounded-[var(--radius-card)] border border-border bg-surface p-6">
                    {children}
                </div>
            </div>
        </div>
    );
}
