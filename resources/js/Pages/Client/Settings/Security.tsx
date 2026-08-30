import { router } from '@inertiajs/react';
import { CheckCircle2, Monitor, ShieldAlert, ShieldCheck, XCircle } from 'lucide-react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';

interface Session {
    id: string;
    ipAddress: string | null;
    userAgent: string | null;
    lastActive: number;
    current: boolean;
}

interface LoginEntry {
    id: number;
    successful: boolean;
    reason: string | null;
    ipAddress: string | null;
    twoFactorUsed: boolean;
    at: string | null;
}

export default function Security({
    twoFactorEnabled,
    sessions,
    loginHistory,
}: {
    twoFactorEnabled: boolean;
    sessions: Session[];
    loginHistory: LoginEntry[];
}) {
    const revoke = (id: string) => {
        // A destructive action, so it is confirmed before it is sent (spec §72).
        if (!window.confirm('Sign this device out? It will need to sign in again.')) {
            return;
        }

        router.delete(route('client.security.sessions.destroy', id), { preserveScroll: true });
    };

    return (
        <ClientLayout title="Security" description="Protect your account and review recent activity.">
            <Card>
                <CardHeader
                    title="Two-factor authentication"
                    description="Require a one-time code in addition to your password."
                    action={
                        twoFactorEnabled ? (
                            <Badge
                                tone="success"
                                icon={<ShieldCheck className="size-3" aria-hidden="true" />}
                            >
                                Enabled
                            </Badge>
                        ) : (
                            <Badge
                                tone="warning"
                                icon={<ShieldAlert className="size-3" aria-hidden="true" />}
                            >
                                Not enabled
                            </Badge>
                        )
                    }
                />
                <CardBody>
                    <p className="text-sm text-muted-foreground">
                        {twoFactorEnabled
                            ? 'Your account asks for an authenticator code at sign-in. Keep your recovery codes somewhere safe.'
                            : 'Add an authenticator app to make your account substantially harder to compromise.'}
                    </p>
                </CardBody>
            </Card>

            <Card>
                <CardHeader
                    title="Active sessions"
                    description="Devices currently signed in to your account."
                />
                <Table caption="Sessions signed in to this account">
                    <thead>
                        <tr>
                            <Th>Device</Th>
                            <Th>IP address</Th>
                            <Th>Last active</Th>
                            <Th className="text-right">Action</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {sessions.map((session) => (
                            <tr key={session.id}>
                                <Td>
                                    <div className="flex items-center gap-2">
                                        <Monitor
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        <span className="max-w-md truncate text-muted-foreground">
                                            {session.userAgent ?? 'Unknown device'}
                                        </span>
                                        {session.current ? <Badge tone="info">This device</Badge> : null}
                                    </div>
                                </Td>
                                <Td className="text-muted-foreground">{session.ipAddress ?? '—'}</Td>
                                <Td className="text-muted-foreground">
                                    {new Date(session.lastActive * 1000).toLocaleString()}
                                </Td>
                                <Td className="text-right">
                                    {session.current ? (
                                        <span className="text-xs text-muted-foreground">—</span>
                                    ) : (
                                        <Button variant="ghost" size="sm" onClick={() => revoke(session.id)}>
                                            Revoke
                                        </Button>
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Card>

            <Card>
                <CardHeader
                    title="Recent sign-in activity"
                    description="The last 20 attempts on this account."
                />
                <Table caption="Recent sign-in attempts">
                    <thead>
                        <tr>
                            <Th>Result</Th>
                            <Th>IP address</Th>
                            <Th>Two-factor</Th>
                            <Th>When</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {loginHistory.map((entry) => (
                            <tr key={entry.id}>
                                <Td>
                                    {entry.successful ? (
                                        <Badge
                                            tone="success"
                                            icon={<CheckCircle2 className="size-3" aria-hidden="true" />}
                                        >
                                            Success
                                        </Badge>
                                    ) : (
                                        <Badge
                                            tone="danger"
                                            icon={<XCircle className="size-3" aria-hidden="true" />}
                                        >
                                            {entry.reason ?? 'Failed'}
                                        </Badge>
                                    )}
                                </Td>
                                <Td className="text-muted-foreground">{entry.ipAddress ?? '—'}</Td>
                                <Td className="text-muted-foreground">
                                    {entry.twoFactorUsed ? 'Yes' : 'No'}
                                </Td>
                                <Td className="text-muted-foreground">
                                    {entry.at ? new Date(entry.at).toLocaleString() : '—'}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>
            </Card>
        </ClientLayout>
    );
}
