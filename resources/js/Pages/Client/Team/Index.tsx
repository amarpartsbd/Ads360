import { ShieldCheck, Users } from 'lucide-react';
import { Badge } from '@/Components/UI/Badge';
import { Card, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';

interface Member {
    id: string;
    name: string;
    email: string;
    status: string;
    statusLabel: string;
    twoFactorEnabled: boolean;
    roles: string[];
    joinedAt: string | null;
}

export default function TeamIndex({
    organization,
    members,
}: {
    organization: { id: string; name: string };
    members: Member[];
    can: { manageUsers: boolean; manageRoles: boolean };
}) {
    return (
        <ClientLayout title="Team" description={`People with access to ${organization.name}.`}>
            <Card>
                <CardHeader title="Members" description={`${members.length} active member(s).`} />

                {members.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title="No team members yet"
                        description="Invite colleagues to collaborate on campaigns."
                    />
                ) : (
                    <Table caption="Active members of this organization">
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Email</Th>
                                <Th>Roles</Th>
                                <Th>Two-factor</Th>
                                <Th>Status</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {members.map((member) => (
                                <tr key={member.id}>
                                    <Td className="font-medium">{member.name}</Td>
                                    <Td className="text-muted-foreground">{member.email}</Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-1">
                                            {member.roles.map((role) => (
                                                <Badge key={role}>{role}</Badge>
                                            ))}
                                        </div>
                                    </Td>
                                    <Td>
                                        {member.twoFactorEnabled ? (
                                            <Badge
                                                tone="success"
                                                icon={<ShieldCheck className="size-3" aria-hidden="true" />}
                                            >
                                                Enabled
                                            </Badge>
                                        ) : (
                                            <Badge tone="warning">Not enabled</Badge>
                                        )}
                                    </Td>
                                    <Td>
                                        <Badge tone={member.status === 'ACTIVE' ? 'success' : 'neutral'}>
                                            {member.statusLabel}
                                        </Badge>
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
