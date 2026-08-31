import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Megaphone, Pause, Play, Plus, Send, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Alert } from '@/Components/UI/Alert';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Textarea } from '@/Components/UI/Textarea';
import ClientLayout from '@/Layouts/ClientLayout';
import type { CampaignOptions, CampaignRow } from '@/Pages/Client/Campaigns/Index';

interface SerialisedMoney {
    formatted?: string;
    amount?: string;
    currency?: string;
}

interface Costs {
    base?: SerialisedMoney;
    committed?: SerialisedMoney;
    feeTotal?: SerialisedMoney;
    total?: SerialisedMoney;
    fees?: { label: string; amount: SerialisedMoney }[];
}

interface AdRow {
    id: string;
    name: string;
    headline: string;
    extraHeadlines: string[];
    extraDescriptions: string[];
    status: string;
    statusLabel: string;
    statusMessage: string;
    creative: string | null;
    identity: string | null;
    destinationUrl: string;
    complete: boolean;
}

interface AdSetRow {
    id: string;
    name: string;
    status: string;
    statusLabel: string;
    statusMessage: string;
    targetingSummary: string;
    bidStrategy: string;
    ads: AdRow[];
}

interface CampaignDetail extends CampaignRow {
    objectiveLabel: string;
    reviewNotes: string | null;
    lastError: string | null;
    startsAt: string | null;
    endsAt: string | null;
    costs: Costs;
    adSets: AdSetRow[];
}

interface Library {
    creatives: { id: string; name: string; type: string; dimensions: string | null }[];
    identities: { id: string; name: string; type: string }[];
}

const money = (value?: SerialisedMoney) => value?.formatted ?? value?.amount ?? '—';

export default function CampaignShow({
    campaign,
    readiness,
    library,
    options,
    can,
}: {
    campaign: CampaignDetail;
    readiness: string[];
    library: Library;
    options: CampaignOptions;
    can: { update: boolean; submit: boolean; pause: boolean };
}) {
    return (
        <ClientLayout
            title={campaign.name}
            description={`${campaign.providerLabel} · ${campaign.objectiveLabel}`}
            actions={
                <div className="flex gap-2">
                    <Button asChild variant="ghost">
                        <Link href={route('client.campaigns.index')}>
                            <ArrowLeft aria-hidden="true" />
                            Back
                        </Link>
                    </Button>
                    {can.pause && campaign.live ? (
                        campaign.status === 'PAUSED' ? (
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        route('client.campaigns.resume', campaign.public_id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Play aria-hidden="true" />
                                Resume
                            </Button>
                        ) : (
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    router.post(
                                        route('client.campaigns.pause', campaign.public_id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Pause aria-hidden="true" />
                                Pause
                            </Button>
                        )
                    ) : null}
                </div>
            }
        >
            <Alert
                tone={
                    campaign.status === 'REJECTED' || campaign.status === 'FAILED'
                        ? 'danger'
                        : campaign.status === 'CHANGES_REQUESTED'
                          ? 'warning'
                          : 'info'
                }
                title={campaign.statusLabel}
            >
                {campaign.statusMessage}
                {campaign.reviewNotes ? <p className="mt-2">{campaign.reviewNotes}</p> : null}
                {campaign.lastError ? <p className="mt-2">{campaign.lastError}</p> : null}
            </Alert>

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader
                        title="What this costs"
                        description="Worked out by us, not by your browser. This is what will be held from your wallet when the campaign is approved."
                    />
                    <CardBody className="space-y-2 text-sm">
                        <Row label="Advertising budget">
                            {money(campaign.costs.committed ?? campaign.costs.base)}
                        </Row>
                        {(campaign.costs.fees ?? []).map((fee, index) => (
                            <Row key={`${fee.label}-${index}`} label={fee.label}>
                                {money(fee.amount)}
                            </Row>
                        ))}
                        <div className="border-t border-border pt-2">
                            <Row label="Total">
                                <span className="text-base font-semibold">{money(campaign.costs.total)}</span>
                            </Row>
                        </div>
                    </CardBody>
                </Card>

                <Card>
                    <CardHeader title="Progress" />
                    <CardBody className="space-y-2 text-sm">
                        <Row label="Status">
                            <StatusBadge status={campaign.status} label={campaign.statusLabel} />
                        </Row>
                        <Row label="Charged so far">{campaign.captured}</Row>
                        <Row label="Held, unspent">{campaign.remaining}</Row>
                        <Row label="Platform reports">{campaign.reportedSpend}</Row>
                    </CardBody>
                </Card>
            </div>

            {campaign.editable && readiness.length > 0 ? (
                <Alert tone="warning" title="Before you can submit this campaign">
                    <ul className="mt-1 list-disc space-y-1 pl-5">
                        {readiness.map((reason) => (
                            <li key={reason}>{reason}</li>
                        ))}
                    </ul>
                </Alert>
            ) : null}

            {campaign.adSets.length === 0 ? (
                <Card>
                    <EmptyState
                        icon={Megaphone}
                        title="No audiences yet"
                        description="An audience says who should see your ads. Add one, then add ads to it."
                    />
                </Card>
            ) : (
                campaign.adSets.map((adSet) => (
                    <AdSetCard
                        key={adSet.id}
                        campaign={campaign}
                        adSet={adSet}
                        library={library}
                        editable={can.update}
                    />
                ))
            )}

            {can.update ? <AddAdSetForm campaign={campaign} options={options} /> : null}

            {can.submit && readiness.length === 0 ? (
                <Card>
                    <CardBody className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            Submitting sends this to our team. Your budget is only held once it is approved.
                        </p>
                        <Button
                            onClick={() =>
                                router.post(
                                    route('client.campaigns.submit', campaign.public_id),
                                    {},
                                    {
                                        preserveScroll: true,
                                    },
                                )
                            }
                        >
                            <Send aria-hidden="true" />
                            Submit for review
                        </Button>
                    </CardBody>
                </Card>
            ) : null}
        </ClientLayout>
    );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium tabular-nums">{children}</span>
        </div>
    );
}

function AdSetCard({
    campaign,
    adSet,
    library,
    editable,
}: {
    campaign: CampaignDetail;
    adSet: AdSetRow;
    library: Library;
    editable: boolean;
}) {
    const [adding, setAdding] = useState(false);

    return (
        <Card>
            <CardHeader
                title={adSet.name}
                description={`${adSet.targetingSummary} · ${adSet.bidStrategy}`}
                action={
                    <div className="flex items-center gap-2">
                        <StatusBadge status={adSet.status} label={adSet.statusLabel} />
                        {editable ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    router.delete(
                                        route('client.campaigns.ad-sets.destroy', {
                                            campaign: campaign.public_id,
                                            adSet: adSet.id,
                                        }),
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Trash2 aria-hidden="true" />
                                Remove
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <CardBody className="space-y-3">
                {adSet.ads.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No ads in this audience yet.</p>
                ) : (
                    adSet.ads.map((ad) => (
                        <div
                            key={ad.id}
                            className="flex flex-wrap items-start justify-between gap-3 rounded-[var(--radius-control)] border border-border p-3"
                        >
                            <div className="min-w-0 space-y-1">
                                <p className="font-medium">{ad.headline}</p>
                                <p className="text-xs text-muted-foreground">
                                    {ad.name} · {ad.creative ?? 'No image'} · as{' '}
                                    {ad.identity ?? 'no page chosen'}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">{ad.destinationUrl}</p>
                                {ad.status !== 'DRAFT' ? (
                                    <p className="text-xs text-muted-foreground">{ad.statusMessage}</p>
                                ) : null}
                            </div>
                            <div className="flex items-center gap-2">
                                <StatusBadge status={ad.status} label={ad.statusLabel} />
                                {editable ? (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.delete(
                                                route('client.campaigns.ads.destroy', {
                                                    campaign: campaign.public_id,
                                                    ad: ad.id,
                                                }),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 aria-hidden="true" />
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    ))
                )}

                {editable ? (
                    adding ? (
                        <AddAdForm
                            campaign={campaign}
                            adSet={adSet}
                            library={library}
                            onDone={() => setAdding(false)}
                        />
                    ) : (
                        <Button variant="secondary" size="sm" onClick={() => setAdding(true)}>
                            <Plus aria-hidden="true" />
                            Add an ad
                        </Button>
                    )
                ) : null}
            </CardBody>
        </Card>
    );
}

function AddAdSetForm({ campaign, options }: { campaign: CampaignDetail; options: CampaignOptions }) {
    const { data, setData, processing, errors, reset } = useForm({
        name: '',
        bid_strategy: options.bidStrategies[0]?.value ?? '',
        bid_amount: '',
        targeting: { countries: 'BD', minimum_age: 18, maximum_age: 65 },
    });

    const strategy = options.bidStrategies.find((entry) => entry.value === data.bid_strategy);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        router.post(
            route('client.campaigns.ad-sets.store', campaign.public_id),
            {
                name: data.name,
                bid_strategy: data.bid_strategy,
                bid_amount: data.bid_amount || null,
                targeting: {
                    countries: data.targeting.countries
                        .split(',')
                        .map((code) => code.trim().toUpperCase())
                        .filter(Boolean),
                    minimum_age: Number(data.targeting.minimum_age),
                    maximum_age: Number(data.targeting.maximum_age),
                },
            },
            { preserveScroll: true, onSuccess: () => reset() },
        );
    };

    return (
        <Card>
            <CardHeader title="Add an audience" description="Who should see the ads in this group." />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                    <Field label="Audience name" error={errors.name} required>
                        {(field) => (
                            <Input
                                {...field}
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field
                        label="Countries"
                        hint="Two-letter codes, separated by commas."
                        error={errors['targeting.countries' as keyof typeof errors] as string | undefined}
                        required
                    >
                        {(field) => (
                            <Input
                                {...field}
                                value={data.targeting.countries}
                                onChange={(event) =>
                                    setData('targeting', {
                                        ...data.targeting,
                                        countries: event.target.value,
                                    })
                                }
                            />
                        )}
                    </Field>

                    <Field label="Minimum age" required>
                        {(field) => (
                            <Input
                                {...field}
                                type="number"
                                min={18}
                                max={65}
                                value={data.targeting.minimum_age}
                                onChange={(event) =>
                                    setData('targeting', {
                                        ...data.targeting,
                                        minimum_age: Number(event.target.value),
                                    })
                                }
                            />
                        )}
                    </Field>

                    <Field label="Maximum age" required>
                        {(field) => (
                            <Input
                                {...field}
                                type="number"
                                min={18}
                                max={65}
                                value={data.targeting.maximum_age}
                                onChange={(event) =>
                                    setData('targeting', {
                                        ...data.targeting,
                                        maximum_age: Number(event.target.value),
                                    })
                                }
                            />
                        )}
                    </Field>

                    <Field label="Bidding" hint={strategy?.description} error={errors.bid_strategy} required>
                        {(field) => (
                            <Select
                                {...field}
                                value={data.bid_strategy}
                                onChange={(event) => setData('bid_strategy', event.target.value)}
                            >
                                {options.bidStrategies.map((entry) => (
                                    <option key={entry.value} value={entry.value}>
                                        {entry.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    {strategy?.requiresAmount ? (
                        <Field label="Bid amount" error={errors.bid_amount} required>
                            {(field) => (
                                <Input
                                    {...field}
                                    inputMode="decimal"
                                    value={data.bid_amount}
                                    onChange={(event) => setData('bid_amount', event.target.value)}
                                />
                            )}
                        </Field>
                    ) : null}

                    <div className="sm:col-span-2">
                        <Button type="submit" loading={processing}>
                            Add audience
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}

function AddAdForm({
    campaign,
    adSet,
    library,
    onDone,
}: {
    campaign: CampaignDetail;
    adSet: AdSetRow;
    library: Library;
    onDone: () => void;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        headline: '',
        primary_text: '',
        description: '',
        extra_headlines: [] as string[],
        extra_descriptions: [] as string[],
        call_to_action: 'LEARN_MORE',
        destination_url: '',
        creative: library.creatives[0]?.id ?? '',
        identity: library.identities[0]?.id ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        post(
            route('client.campaigns.ads.store', {
                campaign: campaign.public_id,
                adSet: adSet.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    reset();
                    onDone();
                },
            },
        );
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-4 rounded-[var(--radius-control)] border border-border p-4 sm:grid-cols-2"
        >
            <Field label="Ad name" hint="For your reference only." error={errors.name} required>
                {(field) => (
                    <Input
                        {...field}
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Headline" error={errors.headline} required>
                {(field) => (
                    <Input
                        {...field}
                        value={data.headline}
                        onChange={(event) => setData('headline', event.target.value)}
                    />
                )}
            </Field>

            <Field label="Main text" error={errors.primary_text} required className="sm:col-span-2">
                {(field) => (
                    <Textarea
                        {...field}
                        rows={3}
                        value={data.primary_text}
                        onChange={(event) => setData('primary_text', event.target.value)}
                    />
                )}
            </Field>

            <CopyList
                label="More headlines"
                hint="Search ads rotate several headlines. Google needs at least three in total, each 30 characters or fewer."
                addLabel="Add a headline"
                maxLength={30}
                limit={14}
                values={data.extra_headlines}
                onChange={(values) => setData('extra_headlines', values)}
            />

            <CopyList
                label="More descriptions"
                hint="Google needs at least two descriptions in total, each 90 characters or fewer."
                addLabel="Add a description"
                maxLength={90}
                limit={3}
                values={data.extra_descriptions}
                onChange={(values) => setData('extra_descriptions', values)}
            />

            <Field label="Where should it link to?" error={errors.destination_url} required>
                {(field) => (
                    <Input
                        {...field}
                        type="url"
                        placeholder="https://"
                        value={data.destination_url}
                        onChange={(event) => setData('destination_url', event.target.value)}
                    />
                )}
            </Field>

            <Field
                label="Image or video"
                hint={
                    library.creatives.length === 0
                        ? 'Upload one from your creative library first.'
                        : undefined
                }
                error={errors.creative}
            >
                {(field) => (
                    <Select
                        {...field}
                        value={data.creative}
                        onChange={(event) => setData('creative', event.target.value)}
                    >
                        <option value="">Choose a file</option>
                        {library.creatives.map((creative) => (
                            <option key={creative.id} value={creative.id}>
                                {creative.name}
                                {creative.dimensions ? ` (${creative.dimensions})` : ''}
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <Field
                label="Appear as"
                hint={
                    library.identities.length === 0
                        ? 'Connect a page from your advertising assets first.'
                        : 'The page or account people will see this ad from.'
                }
                error={errors.identity}
            >
                {(field) => (
                    <Select
                        {...field}
                        value={data.identity}
                        onChange={(event) => setData('identity', event.target.value)}
                    >
                        <option value="">Choose a page</option>
                        {library.identities.map((identity) => (
                            <option key={identity.id} value={identity.id}>
                                {identity.name} ({identity.type})
                            </option>
                        ))}
                    </Select>
                )}
            </Field>

            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit" loading={processing}>
                    Add ad
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

/**
 * A short, repeatable list of ad copy.
 *
 * Providers disagree about how many headlines an ad has: one is enough for a
 * Meta ad, and a Google search ad rotates at least three. Rather than have the
 * platform invent the difference — words the client never wrote appearing
 * under their name — the form asks for them, and says whose requirement it is.
 */
function CopyList({
    label,
    hint,
    addLabel,
    maxLength,
    limit,
    values,
    onChange,
}: {
    label: string;
    hint: string;
    addLabel: string;
    maxLength: number;
    limit: number;
    values: string[];
    onChange: (values: string[]) => void;
}) {
    const update = (index: number, value: string) =>
        onChange(values.map((existing, position) => (position === index ? value : existing)));

    const remove = (index: number) => onChange(values.filter((_, position) => position !== index));

    return (
        <div className="sm:col-span-2">
            <Field label={label} hint={hint}>
                {(field) => (
                    <div className="space-y-2">
                        {values.map((value, index) => (
                            <div key={index} className="flex gap-2">
                                <Input
                                    // Only the first input takes the field's
                                    // id, so the label points at something.
                                    {...(index === 0 ? field : {})}
                                    value={value}
                                    maxLength={maxLength}
                                    onChange={(event) => update(index, event.target.value)}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    aria-label={`Remove ${label.toLowerCase()} ${index + 1}`}
                                    onClick={() => remove(index)}
                                >
                                    <Trash2 className="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        ))}

                        {values.length < limit ? (
                            <Button type="button" variant="ghost" onClick={() => onChange([...values, ''])}>
                                <Plus className="size-4" aria-hidden="true" />
                                {addLabel}
                            </Button>
                        ) : null}
                    </div>
                )}
            </Field>
        </div>
    );
}
