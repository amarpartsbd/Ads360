import { useForm } from '@inertiajs/react';
import { Plug, RefreshCw, Unplug } from 'lucide-react';
import { useState } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';

interface Connection {
    id: string;
    provider: string;
    label: string;
    account_name: string | null;
    status: string;
    statusLabel: string;
    message: string;
    needsAttention: boolean;
    scopes: string[];
    expires_at: string | null;
    lastSyncedAt: string | null;
    can: { refresh: boolean; disconnect: boolean };
}

interface Asset {
    id: string;
    provider: string;
    providerLabel: string;
    type: string;
    typeLabel: string;
    name: string;
    currency: string | null;
    status: string;
    statusLabel: string;
    message: string;
    usable: boolean;
}

interface Connectable {
    value: string;
    label: string;
}

/**
 * What the client has authorised us to use (spec §15, §16).
 *
 * Nothing on this page is a token: the server sends a description of each
 * grant, never the grant itself.
 */
export default function AssetsIndex({
    connections,
    assets,
    connectable,
    can,
}: {
    connections: Connection[];
    assets: Asset[];
    connectable: Connectable[];
    can: { connect: boolean };
}) {
    const needingAttention = connections.filter((connection) => connection.needsAttention);

    return (
        <ClientLayout
            title="Advertising assets"
            description="The advertising accounts, pages and pixels you have connected to Ads360."
        >
            {needingAttention.map((connection) => (
                <Alert
                    key={connection.id}
                    tone="warning"
                    title={`${connection.label}: ${connection.statusLabel}`}
                >
                    {connection.message}
                </Alert>
            ))}

            {can.connect && connectable.length > 0 ? (
                <Card>
                    <CardHeader
                        title="Connect an advertising account"
                        description="You stay signed in at the provider. We only ask for the permissions needed to run your campaigns, and you can withdraw them at any time."
                    />
                    <div className="flex flex-wrap gap-2 px-5 py-4">
                        {connectable.map((provider) => (
                            <Button key={provider.value} asChild variant="secondary">
                                <a
                                    href={route('client.assets.oauth.start', {
                                        provider: provider.value.toLowerCase(),
                                    })}
                                >
                                    <Plug aria-hidden="true" />
                                    Connect {provider.label}
                                </a>
                            </Button>
                        ))}
                    </div>
                </Card>
            ) : null}

            <Card>
                <CardHeader
                    title="Connections"
                    description="One per advertising account you have authorised."
                />

                {connections.length === 0 ? (
                    <EmptyState
                        icon={Plug}
                        title="Nothing connected yet"
                        description="Connect an advertising account to let us publish and manage campaigns on your behalf."
                    />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Account</Th>
                                <Th>Status</Th>
                                <Th>Permissions</Th>
                                <Th>Expires</Th>
                                <Th className="text-right">Actions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {connections.map((connection) => (
                                <ConnectionRow key={connection.id} connection={connection} />
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            <Card>
                <CardHeader
                    title="Connected assets"
                    description="Everything we found on your connected accounts. An asset that is not available cannot be used in a campaign until it is reconnected."
                />

                {assets.length === 0 ? (
                    <EmptyState
                        icon={Plug}
                        title="No assets found yet"
                        description="Assets appear here once a connection has been made and read."
                    />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Type</Th>
                                <Th>Platform</Th>
                                <Th>Currency</Th>
                                <Th>Status</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {assets.map((asset) => (
                                <tr key={asset.id}>
                                    <Td className="font-medium">{asset.name}</Td>
                                    <Td>{asset.typeLabel}</Td>
                                    <Td>{asset.providerLabel}</Td>
                                    <Td>{asset.currency ?? '—'}</Td>
                                    <Td>
                                        <div className="flex flex-col gap-1">
                                            <StatusBadge status={asset.status} label={asset.statusLabel} />
                                            {!asset.usable ? (
                                                <span className="text-xs text-muted-foreground">
                                                    {asset.message}
                                                </span>
                                            ) : null}
                                        </div>
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>
        </ClientLayout>
    );
}

function ConnectionRow({ connection }: { connection: Connection }) {
    const [confirming, setConfirming] = useState(false);
    const { processing, post, delete: destroy } = useForm({});

    return (
        <tr>
            <Td>
                <div className="flex flex-col">
                    <span className="font-medium">{connection.label}</span>
                    <span className="text-xs text-muted-foreground">{connection.account_name ?? '—'}</span>
                </div>
            </Td>
            <Td>
                <div className="flex flex-col gap-1">
                    <StatusBadge status={connection.status} label={connection.statusLabel} />
                    {connection.needsAttention ? (
                        <span className="text-xs text-muted-foreground">{connection.message}</span>
                    ) : null}
                </div>
            </Td>
            <Td>
                <span className="text-xs text-muted-foreground">
                    {connection.scopes.length > 0 ? connection.scopes.join(', ') : '—'}
                </span>
            </Td>
            <Td>{connection.expires_at ? new Date(connection.expires_at).toLocaleDateString() : '—'}</Td>
            <Td className="text-right">
                <div className="flex justify-end gap-2">
                    {connection.can.refresh ? (
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            onClick={() =>
                                post(route('client.assets.connections.sync', connection.id), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <RefreshCw aria-hidden="true" />
                            Refresh
                        </Button>
                    ) : null}

                    {connection.can.disconnect ? (
                        confirming ? (
                            <>
                                <Button
                                    variant="danger"
                                    size="sm"
                                    disabled={processing}
                                    onClick={() =>
                                        destroy(
                                            route('client.assets.connections.disconnect', connection.id),
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    Confirm
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setConfirming(false)}>
                                    Cancel
                                </Button>
                            </>
                        ) : (
                            <Button variant="ghost" size="sm" onClick={() => setConfirming(true)}>
                                <Unplug aria-hidden="true" />
                                Disconnect
                            </Button>
                        )
                    ) : null}
                </div>
            </Td>
        </tr>
    );
}
