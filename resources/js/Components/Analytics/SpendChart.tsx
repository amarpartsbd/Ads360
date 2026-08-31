import { useId, useMemo, useState } from 'react';

export interface SeriesPoint {
    date: string;
    spendMinor: number;
    spend: string;
    impressions: number;
    clicks: number;
    conversions: number;
    /** False when the provider never reported that day, as opposed to reporting zero. */
    reported: boolean;
}

/**
 * Daily spend over the selected window.
 *
 * One series, so there is no legend — the heading names it — and no
 * categorical palette to get wrong. The single hue is the design system's
 * accent, which passes the lightness, chroma and contrast checks against both
 * the light and dark chart surfaces.
 *
 * Days the provider never reported are drawn as gaps rather than as zero. A
 * line dropping to the floor would claim a campaign spent nothing that day;
 * the truth is that nobody has told us yet.
 */
export function SpendChart({
    series,
    currency,
    height = 220,
}: {
    series: SeriesPoint[];
    currency: string;
    height?: number;
}) {
    const gradientId = useId();
    const [hovered, setHovered] = useState<number | null>(null);

    // A fixed viewBox with preserveAspectRatio="none" lets the chart fill any
    // width without recomputing on resize.
    const width = 720;
    const padding = { top: 12, right: 8, bottom: 24, left: 8 };
    const plotWidth = width - padding.left - padding.right;
    const plotHeight = height - padding.top - padding.bottom;

    const { points, maxSpend, gaps } = useMemo(() => {
        const max = Math.max(1, ...series.map((point) => point.spendMinor));

        const mapped = series.map((point, index) => ({
            ...point,
            index,
            x:
                padding.left +
                (series.length === 1 ? plotWidth / 2 : (index / (series.length - 1)) * plotWidth),
            y: padding.top + plotHeight - (point.spendMinor / max) * plotHeight,
        }));

        return {
            points: mapped,
            maxSpend: max,
            gaps: mapped.filter((point) => !point.reported).length,
        };
    }, [series, plotWidth, plotHeight, padding.left, padding.top]);

    if (series.length === 0) {
        return (
            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                No performance data for this period yet.
            </p>
        );
    }

    // Reported runs only, so a gap in the data is a gap in the line.
    const runs: (typeof points)[] = [];
    let run: typeof points = [];

    for (const point of points) {
        if (point.reported) {
            run.push(point);
        } else if (run.length > 0) {
            runs.push(run);
            run = [];
        }
    }

    if (run.length > 0) {
        runs.push(run);
    }

    const active = hovered === null ? null : points[hovered];
    const baseline = padding.top + plotHeight;

    return (
        <figure className="m-0">
            <svg
                viewBox={`0 0 ${width} ${height}`}
                preserveAspectRatio="none"
                className="h-56 w-full"
                role="img"
                aria-label={`Daily advertising spend in ${currency} across the selected period`}
                onMouseLeave={() => setHovered(null)}
            >
                <defs>
                    <linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="var(--color-primary)" stopOpacity="0.18" />
                        <stop offset="100%" stopColor="var(--color-primary)" stopOpacity="0" />
                    </linearGradient>
                </defs>

                {/* Recessive grid: three lines, no numbers on the plot itself. */}
                {[0, 0.5, 1].map((fraction) => (
                    <line
                        key={fraction}
                        x1={padding.left}
                        x2={width - padding.right}
                        y1={padding.top + plotHeight * fraction}
                        y2={padding.top + plotHeight * fraction}
                        stroke="var(--color-border)"
                        strokeWidth="1"
                    />
                ))}

                {runs.map((segment, segmentIndex) => {
                    const first = segment[0];
                    const last = segment[segment.length - 1];

                    if (first === undefined || last === undefined) {
                        return null;
                    }

                    const line = segment
                        .map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`)
                        .join(' ');

                    // A single reported day has a point but no area to fill.
                    const area =
                        segment.length > 1 ? `${line} L${last.x},${baseline} L${first.x},${baseline} Z` : '';

                    return (
                        <g key={segmentIndex}>
                            {area ? <path d={area} fill={`url(#${gradientId})`} /> : null}
                            <path
                                d={line}
                                fill="none"
                                stroke="var(--color-primary)"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                vectorEffect="non-scaling-stroke"
                            />
                        </g>
                    );
                })}

                {active && active.reported ? (
                    <>
                        <line
                            x1={active.x}
                            x2={active.x}
                            y1={padding.top}
                            y2={baseline}
                            stroke="var(--color-border)"
                            strokeWidth="1"
                        />
                        <circle
                            cx={active.x}
                            cy={active.y}
                            r="5"
                            fill="var(--color-primary)"
                            stroke="var(--color-surface)"
                            strokeWidth="2"
                        />
                    </>
                ) : null}

                {/* Hit targets wider than the marks, so hovering is forgiving. */}
                {points.map((point) => (
                    <rect
                        key={point.date}
                        x={point.x - plotWidth / Math.max(1, series.length) / 2}
                        y={padding.top}
                        width={Math.max(6, plotWidth / Math.max(1, series.length))}
                        height={plotHeight}
                        fill="transparent"
                        onMouseEnter={() => setHovered(point.index)}
                    />
                ))}
            </svg>

            <figcaption className="flex flex-wrap items-center justify-between gap-2 px-1 pt-2 text-xs text-muted-foreground">
                <span>{series[0]?.date}</span>
                {active ? (
                    <span className="font-medium text-foreground">
                        {active.date}: {active.reported ? active.spend : 'not reported yet'}
                        {active.reported ? ` · ${active.clicks.toLocaleString()} clicks` : ''}
                    </span>
                ) : (
                    <span>
                        Peak {formatPeak(maxSpend, series)}
                        {gaps > 0 ? ` · ${gaps} day${gaps === 1 ? '' : 's'} not reported yet` : ''}
                    </span>
                )}
                <span>{series[series.length - 1]?.date}</span>
            </figcaption>
        </figure>
    );
}

/** The formatted spend of the highest day, taken from the server's own string. */
function formatPeak(maxSpend: number, series: SeriesPoint[]): string {
    return series.find((point) => point.spendMinor === maxSpend)?.spend ?? '—';
}
