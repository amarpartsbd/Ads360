import { router, useForm } from '@inertiajs/react';
import { BarChart3, Download, FileText } from 'lucide-react';
import { type FormEvent } from 'react';
import { SpendChart, type SeriesPoint } from '@/Components/Analytics/SpendChart';
import { StatTile } from '@/Components/Analytics/StatTile';
import { Button } from '@/Components/UI/Button';
import { Card, CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Field } from '@/Components/UI/Field';
import { Input } from '@/Components/UI/Input';
import { Select } from '@/Components/UI/Select';
import { StatusBadge } from '@/Components/UI/StatusBadge';
import { Table, Td, Th } from '@/Components/UI/Table';
import ClientLayout from '@/Layouts/ClientLayout';

interface Totals {
    spend: string;
    spendMinor: number;
    impressions: number;
    clicks: number;
    reach: number;
    conversions: number;
    conversionValue: string;
    clickThroughRate: string | null;
    costPerClick: string | null;
    costPerMille: string | null;
    costPerConversion: string | null;
    returnOnAdSpend: string | null;
}

interface CampaignRow extends Totals {
    id: string;
    name: string;
    status: string;
    statusLabel: string;
}

interface ExportRow {
    id: string;
    type: string;
    typeLabel: string;
    status: string;
    statusLabel: string;
    message: string;
    period: string;
    rows: number | null;
    downloadable: boolean;
    requestedAt: string | null;
}

/**
 * A client's performance figures (spec §38, §39).
 *
 * Every number here arrived formatted. Nothing on this page adds, averages or
 * divides — a client looking at a total and the platform looking at the same
 * total must see the same number, and two places computing it is two places
 * for them to differ.
 */
export default function AnalyticsIndex({
    totals,
    change,
    series,
    campaigns,
    filters,
    currencies,
    exports: exportRows,
    reportTypes,
    can,
}: {
    totals: Totals;
    previous: Totals;
    change: string | null;
    series: SeriesPoint[];
    campaigns: CampaignRow[];
    filters: { from: string; to: string; currency: string };
    currencies: string[];
    exports: ExportRow[];
    reportTypes: { value: string; label: string; description: string }[];
    can: { export: boolean };
}) {
    const applyFilters = (next: Partial<typeof filters>) => {
        router.get(
            route('client.analytics.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    };

    const changeDirection =
        change === null ? null : Number(change) > 0 ? 'up' : Number(change) < 0 ? 'down' : null;

    return (
        <ClientLayout
            title="Analytics"
            description="What your campaigns did, as the advertising platforms report it."
        >
            <Card>
                <CardBody className="flex flex-wrap items-end gap-3">
                    <Field label="From" className="w-40">
                        {(field) => (
                            <Input
                                {...field}
                                type="date"
                                value={filters.from}
                                onChange={(event) => applyFilters({ from: event.target.value })}
                            />
                        )}
                    </Field>

                    <Field label="To" className="w-40">
                        {(field) => (
                            <Input
                                {...field}
                                type="date"
                                value={filters.to}
                                onChange={(event) => applyFilters({ to: event.target.value })}
                            />
                        )}
                    </Field>

                    {currencies.length > 1 ? (
                        <Field label="Currency" className="w-32">
                            {(field) => (
                                <Select
                                    {...field}
                                    value={filters.currency}
                                    onChange={(event) => applyFilters({ currency: event.target.value })}
                                >
                                    {currencies.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </Field>
                    ) : null}
                </CardBody>
            </Card>

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Spend"
                    value={totals.spend}
                    change={change}
                    changeDirection={changeDirection}
                />
                <StatTile
                    label="Impressions"
                    value={totals.impressions.toLocaleString()}
                    hint={totals.costPerMille ? `${totals.costPerMille} per thousand` : 'No impressions yet'}
                />
                <StatTile
                    label="Clicks"
                    value={totals.clicks.toLocaleString()}
                    hint={
                        totals.clickThroughRate
                            ? `${totals.clickThroughRate}% click-through · ${totals.costPerClick} each`
                            : 'No clicks yet'
                    }
                />
                <StatTile
                    label="Conversions"
                    value={totals.conversions.toLocaleString()}
                    hint={
                        totals.costPerConversion
                            ? `${totals.costPerConversion} each · ${totals.returnOnAdSpend}× return`
                            : 'No conversions recorded yet'
                    }
                />
            </div>

            <Card>
                <CardHeader
                    title="Daily spend"
                    description="Advertising platforms revise recent days as attribution settles, so the last few can still move."
                />
                <CardBody>
                    <SpendChart series={series} currency={filters.currency} />
                </CardBody>
            </Card>

            <Card>
                <CardHeader title="By campaign" description="Largest spender first." />

                {campaigns.length === 0 ? (
                    <EmptyState
                        icon={BarChart3}
                        title="Nothing to show for this period"
                        description="Once a campaign is running, its figures appear here within the hour."
                    />
                ) : (
                    <Table caption="Performance by campaign">
                        <thead>
                            <tr>
                                <Th>Campaign</Th>
                                <Th className="text-right">Spend</Th>
                                <Th className="text-right">Impressions</Th>
                                <Th className="text-right">Clicks</Th>
                                <Th className="text-right">CTR</Th>
                                <Th className="text-right">Conversions</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {campaigns.map((campaign) => (
                                <tr key={campaign.id}>
                                    <Td>
                                        <span className="font-medium">{campaign.name}</span>
                                        <p className="mt-1">
                                            <StatusBadge
                                                status={campaign.status}
                                                label={campaign.statusLabel}
                                            />
                                        </p>
                                    </Td>
                                    <Td className="text-right tabular-nums">{campaign.spend}</Td>
                                    <Td className="text-right tabular-nums">
                                        {campaign.impressions.toLocaleString()}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {campaign.clicks.toLocaleString()}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {campaign.clickThroughRate ? `${campaign.clickThroughRate}%` : '—'}
                                    </Td>
                                    <Td className="text-right tabular-nums">
                                        {campaign.conversions.toLocaleString()}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Card>

            {can.export ? <ExportPanel filters={filters} reportTypes={reportTypes} /> : null}

            {exportRows.length > 0 ? (
                <Card>
                    <CardHeader
                        title="Your reports"
                        description="Files are removed after a week. Generate a fresh copy whenever you need one."
                    />
                    <Table caption="Generated reports">
                        <thead>
                            <tr>
                                <Th>Report</Th>
                                <Th>Period</Th>
                                <Th>Status</Th>
                                <Th className="text-right">Rows</Th>
                                <Th className="text-right">Download</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {exportRows.map((row) => (
                                <tr key={row.id}>
                                    <Td className="font-medium">{row.typeLabel}</Td>
                                    <Td>{row.period}</Td>
                                    <Td>
                                        <span>{row.statusLabel}</span>
                                        <p className="text-xs text-muted-foreground">{row.message}</p>
                                    </Td>
                                    <Td className="text-right tabular-nums">{row.rows ?? '—'}</Td>
                                    <Td className="text-right">
                                        {row.downloadable ? (
                                            <Button asChild variant="ghost" size="sm">
                                                <a href={route('client.analytics.exports.download', row.id)}>
                                                    <Download aria-hidden="true" />
                                                    CSV
                                                </a>
                                            </Button>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">—</span>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Card>
            ) : null}
        </ClientLayout>
    );
}

function ExportPanel({
    filters,
    reportTypes,
}: {
    filters: { from: string; to: string };
    reportTypes: { value: string; label: string; description: string }[];
}) {
    const { data, setData, post, processing, errors } = useForm({
        type: reportTypes[0]?.value ?? '',
        from: filters.from,
        to: filters.to,
    });

    const selected = reportTypes.find((type) => type.value === data.type);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('client.analytics.exports.store'), { preserveScroll: true });
    };

    return (
        <Card>
            <CardHeader
                title="Export a report"
                description="Reports are prepared in the background and appear below when they are ready."
            />
            <CardBody>
                <form onSubmit={submit} className="grid gap-4 sm:grid-cols-4">
                    <Field
                        label="Report"
                        hint={selected?.description}
                        error={errors.type}
                        className="sm:col-span-2"
                        required
                    >
                        {(field) => (
                            <Select
                                {...field}
                                value={data.type}
                                onChange={(event) => setData('type', event.target.value)}
                            >
                                {reportTypes.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </Select>
                        )}
                    </Field>

                    <Field label="From" error={errors.from} required>
                        {(field) => (
                            <Input
                                {...field}
                                type="date"
                                value={data.from}
                                onChange={(event) => setData('from', event.target.value)}
                            />
                        )}
                    </Field>

                    <Field label="To" error={errors.to} required>
                        {(field) => (
                            <Input
                                {...field}
                                type="date"
                                value={data.to}
                                onChange={(event) => setData('to', event.target.value)}
                            />
                        )}
                    </Field>

                    <div className="sm:col-span-4">
                        <Button type="submit" loading={processing}>
                            <FileText aria-hidden="true" />
                            Generate report
                        </Button>
                    </div>
                </form>
            </CardBody>
        </Card>
    );
}
