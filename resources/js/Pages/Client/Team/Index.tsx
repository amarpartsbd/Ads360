import { router, useForm } from '@inertiajs/react';
import { Mail, ShieldCheck, UserPlus, Users } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';

interface Member {
    id: string;
    name: string;
    email: string;
    membershipStatus: string | null;
    membershipStatusLabel: string | null;
    twoFactorEnabled: boolean;
    roles: { id: string; name: string }[];
    joinedAt: string | null;
    isSelf: boolean;
}

interface Invitation {
    id: string;
    email: string;
    name: string | null;
    role: string;
    expiresAt: string;
    expired: boolean;
    lastSentAt: string | null;
}

interface RoleOption {
    id: string;
    name: string;
    description: string;
}

export default function TeamIndex({
    organization,
    members,
    invitations,
    assignableRoles,
    can,
}: {
    organization: { id: string; name: string };
    members: Member[];
    invitations: Invitation[];
    assignableRoles: RoleOption[];
    can: { manageUsers: boolean; manageRoles: boolean };
}) {
    return (
        <ClientLayout title="Team" description={`People with access to ${organization.name}.`}>
            {can.manageUsers && assignableRoles.length > 0 ? <InviteForm roles={assignableRoles} /> : null}

            {invitations.length > 0 ? (
                <InvitationsTable invitations={invitations} canManage={can.manageUsers} />
            ) : null}

            <MembersTable members={members} roles={assignableRoles} can={can} />
        </ClientLayout>
    );
}

function InviteForm({ roles }: { roles: RoleOption[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        name: '',
        role: roles[0]?.id ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('client.team.invite'), {
            preserveScroll: true,
            onSuccess: () => reset('email', 'name'),
        });
    };

    const selected = roles.find((role) => role.id === data.role);

    return (
        <Card>
            <CardHeader
                title="Invite a team member"
                description="They will receive an email with a link that expires in seven days."
            />
            <CardBody>
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <Field label="Email address" error={errors.email} required className="min-w-64 flex-1">
                        {(props) => (
                            <Input
                                {...props}
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="Name" error={errors.name} className="min-w-48 flex-1">
                        {(props) => (
                            <Input
                                {...props}
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Role"
                        error={errors.role}
                        required
                        hint={selected?.description}
                        className="min-w-52 flex-1"
                    >
                        {(props) => (
                            <Select
                                {...props}
                                value={data.role}
                                onChange={(event) => setData('role', event.target.value)}
                            >
                                {roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.name}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Button type="submit" loading={processing}>
                        <UserPlus aria-hidden="true" />
                        Send invitation
                    </Button>
                </form>
            </CardBody>
        </Card>
    );
}

function InvitationsTable({ invitations, canManage }: { invitations: Invitation[]; canManage: boolean }) {
    const revoke = (invitation: Invitation) => {
        if (!window.confirm(`Revoke the invitation for ${invitation.email}?`)) {
            return;
        }
        router.delete(route('client.team.invitations.revoke', invitation.id), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader title="Pending invitations" description={`${invitations.length} outstanding.`} />
            <Table caption="Invitations awaiting acceptance">
                <thead>
                    <tr>
                        <Th>Email</Th>
                        <Th>Role</Th>
                        <Th>Expires</Th>
                        {canManage ? <Th className="text-right">Actions</Th> : null}
                    </tr>
                </thead>
                <tbody>
                    {invitations.map((invitation) => (
                        <tr key={invitation.id}>
                            <Td>
                                <span className="font-medium">{invitation.email}</span>
                                {invitation.name ? (
                                    <span className="ml-2 text-muted-foreground">{invitation.name}</span>
                                ) : null}
                            </Td>
                            <Td>
                                <Badge>{invitation.role}</Badge>
                            </Td>
                            <Td className="text-muted-foreground">
                                {invitation.expired ? (
                                    <StatusBadge status="EXPIRED" label="Expired" />
                                ) : (
                                    new Date(invitation.expiresAt).toLocaleDateString()
                                )}
                            </Td>
                            {canManage ? (
                                <Td className="space-x-1 text-right">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                route('client.team.invitations.resend', invitation.id),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Mail aria-hidden="true" />
                                        Resend
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => revoke(invitation)}>
                                        Revoke
                                    </Button>
                                </Td>
                            ) : null}
                        </tr>
                    ))}
                </tbody>
            </Table>
        </Card>
    );
}

function MembersTable({
    members,
    roles,
    can,
}: {
    members: Member[];
    roles: RoleOption[];
    can: { manageUsers: boolean; manageRoles: boolean };
}) {
    const [editing, setEditing] = useState<string | null>(null);

    const act = (member: Member, action: 'suspend' | 'reinstate' | 'remove') => {
        const prompts: Record<typeof action, string> = {
            suspend: `Suspend ${member.name}? They will lose access immediately.`,
            reinstate: `Restore access for ${member.name}?`,
            remove: `Remove ${member.name} from this organization? Their roles here will be revoked.`,
        };

        if (!window.confirm(prompts[action])) {
            return;
        }

        if (action === 'remove') {
            router.delete(route('client.team.members.remove', member.id), { preserveScroll: true });

            return;
        }

        router.post(route(`client.team.members.${action}`, member.id), {}, { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader title="Members" description={`${members.length} in this organization.`} />

            {members.length === 0 ? (
                <EmptyState icon={Users} title="No team members yet" />
            ) : (
                <Table caption="Members of this organization">
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Roles</Th>
                            <Th>Two-factor</Th>
                            <Th>Status</Th>
                            {can.manageUsers ? <Th className="text-right">Actions</Th> : null}
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((member) => (
                            <tr key={member.id}>
                                <Td>
                                    <span className="font-medium">{member.name}</span>
                                    {member.isSelf ? <Badge className="ml-2">You</Badge> : null}
                                    <span className="block text-xs text-muted-foreground">
                                        {member.email}
                                    </span>
                                </Td>
                                <Td>
                                    {editing === member.id ? (
                                        <RoleEditor
                                            member={member}
                                            roles={roles}
                                            onDone={() => setEditing(null)}
                                        />
                                    ) : (
                                        <div className="flex flex-wrap items-center gap-1">
                                            {member.roles.map((role) => (
                                                <Badge key={role.id}>{role.name}</Badge>
                                            ))}
                                            {can.manageRoles && !member.isSelf ? (
                                                <Button
                                                    variant="link"
                                                    size="sm"
                                                    onClick={() => setEditing(member.id)}
                                                >
                                                    Change
                                                </Button>
                                            ) : null}
                                        </div>
                                    )}
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
                                    <StatusBadge
                                        status={member.membershipStatus ?? 'ACTIVE'}
                                        label={member.membershipStatusLabel ?? 'Active'}
                                    />
                                </Td>
                                {can.manageUsers ? (
                                    <Td className="space-x-1 text-right">
                                        {member.isSelf ? (
                                            <span className="text-xs text-muted-foreground">—</span>
                                        ) : (
                                            <>
                                                {member.membershipStatus === 'SUSPENDED' ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => act(member, 'reinstate')}
                                                    >
                                                        Reinstate
                                                    </Button>
                                                ) : (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => act(member, 'suspend')}
                                                    >
                                                        Suspend
                                                    </Button>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => act(member, 'remove')}
                                                >
                                                    Remove
                                                </Button>
                                            </>
                                        )}
                                    </Td>
                                ) : null}
                            </tr>
                        ))}
                    </tbody>
                </Table>
            )}
        </Card>
    );
}

function RoleEditor({ member, roles, onDone }: { member: Member; roles: RoleOption[]; onDone: () => void }) {
    const { data, setData, put, processing, errors } = useForm({
        roles: member.roles.map((role) => role.id),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        put(route('client.team.members.roles', member.id), {
            preserveScroll: true,
            onSuccess: onDone,
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
            <label className="sr-only" htmlFor={`roles-${member.id}`}>
                Roles for {member.name}
            </label>
            <Select
                id={`roles-${member.id}`}
                value={data.roles[0] ?? ''}
                onChange={(event) => setData('roles', [event.target.value])}
                aria-invalid={Boolean(errors.roles)}
                className="w-48"
            >
                {roles.map((role) => (
                    <option key={role.id} value={role.id}>
                        {role.name}
                    </option>
                ))}
            </Select>
            <Button type="submit" size="sm" loading={processing}>
                Save
            </Button>
            <Button type="button" size="sm" variant="ghost" onClick={onDone}>
                Cancel
            </Button>
            {errors.roles ? (
                <p role="alert" className="w-full text-xs text-danger">
                    {errors.roles}
                </p>
            ) : null}
        </form>
    );
}
