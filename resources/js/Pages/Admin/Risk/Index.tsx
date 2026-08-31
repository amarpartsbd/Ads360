import { router, useForm } from '@inertiajs/react';
import { CheckCircle2, Flag, RefreshCw, ShieldAlert } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Badge } from '@/Components/UI/Badge';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import AdminLayout from '@/Layouts/AdminLayout';
import type { Paginated } from '@/Types';

interface Factor {
    label: string;
    points: number;
    detail: string;
    remedy: string | null;
}

interface RiskRow {
    id: string;
    organization: { id: string | null; name: string; status: string | null };
    score: number;
    level: string;
    levelLabel: string;
    guidance: string;
    factors: Factor[];
    requiresSecondApprover: boolean;
    flagged: boolean;
    flagReason: string | null;
    flaggedBy: string | null;
    reviewedAt: string | null;
    reviewedBy: string | null;
    reviewNote: string | null;
    assessedAt: string | null;
    isStale: boolean;
}

/** Colour is never the only signal: every level carries a word too (spec §74). */
function levelTone(level: string): 'neutral' | 'info' | 'warning' | 'danger' {
    switch (level) {
        case 'CRITICAL':
            return 'danger';
        case 'HIGH':
            return 'warning';
        case 'MEDIUM':
            return 'info';
        default:
            return 'neutral';
    }
}

export default function RiskIndex({
    profiles,
    filters,
    levels,
    can,
}: {
    profiles: Paginated<RiskRow>;
    filters: { level: string | null; unreviewed: boolean };
    levels: { value: string; label: string; guidance: string }[];
    can: { manage: boolean };
}) {
    return (
        <AdminLayout
            title="Client risk"
            description="Scores are computed from stored facts, and every point has a reason beside it."
        >
            <Alert tone="info" title="A score never acts on its own">
                The only automatic consequence of a high score is that financial actions on the client need a
                second approver. Suspending an account, freezing a wallet or stopping a campaign remains a
                decision for a person, taken where it always was.
            </Alert>

            <Card>
                <CardBody>
                    <div className="flex flex-wrap items-end gap-3">
                        <Field label="Level" className="min-w-48">
                            {(field) => (
                                <Select
                                    {...field}
                                    value={filters.level ?? ''}
                                    onChange={(event) =>
                                        router.get(
                                            route('admin.risk.index'),
                                            {
                                                level: event.target.value || undefined,
                                                unreviewed: filters.unreviewed || undefined,
                                            },
                                            { preserveState: true, replace: true },
                                        )
                                    }
                                >
                                    <option value="">High and critical</option>
                                    {levels.map((level) => (
                                        <option key={level.value} value={level.value}>
                                            {level.label}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>

                        <Button
                            variant={filters.unreviewed ? 'primary' : 'ghost'}
                            onClick={() =>
                                router.get(
                                    route('admin.risk.index'),
                                    {
                                        level: filters.level || undefined,
                                        unreviewed: filters.unreviewed ? undefined : true,
                                    },
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            Not yet reviewed
                        </Button>
                    </div>
                </CardBody>
            </Card>

            {profiles.data.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={ShieldAlert}
                        title="Nothing needs attention"
                        description="No client is currently scoring high or critical."
                    />
                </Card>
            ) : (
                profiles.data.map((profile) => (
                    <RiskCard key={profile.id} profile={profile} canManage={can.manage} />
                ))
            )}
        </AdminLayout>
    );
}

function RiskCard({ profile, canManage }: { profile: RiskRow; canManage: boolean }) {
    const [flagging, setFlagging] = useState(false);

    return (
        <Card>
            <CardHeader
                title={profile.organization.name}
                description={profile.guidance}
                action={
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={levelTone(profile.level)}>
                            {profile.levelLabel} · {profile.score}/100
                        </Badge>
                        {profile.flagged ? <Badge tone="danger">Flagged</Badge> : null}
                        {profile.reviewedAt ? <Badge tone="neutral">Reviewed</Badge> : null}
                        {profile.isStale ? <Badge tone="neutral">Assessment is stale</Badge> : null}
                    </div>
                }
            />
            <CardBody className="space-y-4">
                {profile.factors.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Nothing on this account raises its risk.</p>
                ) : (
                    <ul className="space-y-2">
                        {profile.factors.map((factor, index) => (
                            <li
                                key={index}
                                className="flex items-start justify-between gap-4 border-b border-border pb-2 last:border-0"
                            >
                                <div>
                                    <p className="text-sm font-medium">{factor.label}</p>
                                    <p className="text-sm text-muted-foreground">{factor.detail}</p>
                                    {factor.remedy ? (
                                        <p className="text-xs text-muted-foreground">
                                            What fixes it: {factor.remedy}
                                        </p>
                                    ) : null}
                                </div>
                                <span className="shrink-0 text-sm text-muted-foreground tabular-nums">
                                    +{factor.points}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {profile.requiresSecondApprover ? (
                    <p className="text-sm text-warning-foreground">
                        Financial actions on this client currently need a second approver.
                    </p>
                ) : null}

                {profile.reviewedAt ? (
                    <p className="text-xs text-muted-foreground">
                        Reviewed by {profile.reviewedBy ?? 'someone'}
                        {profile.reviewNote ? ` — ${profile.reviewNote}` : ''}
                    </p>
                ) : null}

                {canManage && profile.organization.id ? (
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="ghost"
                            onClick={() =>
                                router.post(
                                    route('admin.risk.reassess', {
                                        organization: profile.organization.id,
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <RefreshCw className="size-4" aria-hidden="true" />
                            Reassess now
                        </Button>

                        {!profile.reviewedAt ? (
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    router.post(
                                        route('admin.risk.reviewed', {
                                            organization: profile.organization.id,
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <CheckCircle2 className="size-4" aria-hidden="true" />
                                Mark reviewed
                            </Button>
                        ) : null}

                        <Button variant="ghost" onClick={() => setFlagging((open) => !open)}>
                            <Flag className="size-4" aria-hidden="true" />
                            {profile.flagged ? 'Clear flag' : 'Flag'}
                        </Button>
                    </div>
                ) : null}

                {flagging && profile.organization.id ? (
                    <FlagForm
                        organizationId={profile.organization.id}
                        clearing={profile.flagged}
                        onDone={() => setFlagging(false)}
                    />
                ) : null}

                {profile.flagged && profile.flagReason ? (
                    <p className="text-xs text-muted-foreground">
                        Flagged{profile.flaggedBy ? ` by ${profile.flaggedBy}` : ''}: {profile.flagReason}
                    </p>
                ) : null}
            </CardBody>
        </Card>
    );
}

function FlagForm({
    organizationId,
    clearing,
    onDone,
}: {
    organizationId: string;
    clearing: boolean;
    onDone: () => void;
}) {
    const { data, setData, post, delete: destroy, processing, errors, reset } = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const target = clearing
            ? route('admin.risk.flag.clear', { organization: organizationId })
            : route('admin.risk.flag', { organization: organizationId });

        const done = () => {
            reset();
            onDone();
        };

        if (clearing) {
            destroy(target, { preserveScroll: true, onSuccess: done });
        } else {
            post(target, { preserveScroll: true, onSuccess: done });
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-t border-border pt-4">
            <Field
                label={clearing ? 'Why is the flag being cleared?' : 'Why is this being flagged?'}
                hint="Stored with the profile. A flag is worth a fifth of the whole scale."
                error={errors.reason}
                className="min-w-72 grow"
                required
            >
                {(field) => (
                    <Input
                        {...field}
                        value={data.reason}
                        onChange={(event) => setData('reason', event.target.value)}
                    />
                )}
            </Field>

            <Button type="submit" loading={processing}>
                {clearing ? 'Clear flag' : 'Flag client'}
            </Button>
            <Button type="button" variant="ghost" onClick={onDone}>
                Cancel
            </Button>
        </form>
    );
}
