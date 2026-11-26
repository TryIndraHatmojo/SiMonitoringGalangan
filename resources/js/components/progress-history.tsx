import { percent, type Period } from '@/types/monitoring';

export function History({ history }: { history: Period[] }) {
    const dates = history.map((period) => Date.parse(period.end));
    const start = dates[0] ?? 0;
    const span = (dates.at(-1) ?? start) - start;
    const shortestInterval = dates.reduce(
        (shortest, date, index) =>
            index === 0
                ? shortest
                : Math.min(shortest, date - dates[index - 1]),
        Infinity,
    );
    const labelSpace = Math.max(
        80,
        ...history.map((period) => Array.from(period.axis_label).length * 9),
    );
    const plotLeft = 48;
    const minWidth = Math.max(
        460,
        plotLeft + Math.ceil((24 * span) / shortestInterval) + labelSpace,
    );
    const width = Math.max(755, minWidth);
    const plotRight = width - labelSpace;
    const x = (period: Period) =>
        span
            ? plotLeft +
              ((plotRight - plotLeft) * (Date.parse(period.end) - start)) / span
            : (plotLeft + plotRight) / 2;
    const y = (value: number) => 170 - 1.45 * value;
    return (
        <section className="min-w-0 border-progress-grid bg-progress-surface text-progress-foreground">
            <h2>S Curve pembangunan</h2>
            {!history.length ? (
                <span>Belum ada periode laporan.</span>
            ) : (
                <>
                    <div className="flex flex-wrap gap-5 text-sm">
                        <span className="text-progress-actual">
                            ● Realisasi
                        </span>
                        <span className="text-progress-plan">┄ Rencana</span>
                        {history.some(
                            (p) => p.partial && p.actual !== null,
                        ) && <span>○ Parsial</span>}
                    </div>
                    <div
                        className="overflow-x-auto"
                        role="region"
                        aria-label="Grafik S Curve"
                        tabIndex={0}
                    >
                        <svg
                            role="img"
                            aria-label="S Curve kumulatif dengan label periode pada sumbu X. Rincian tersedia dalam tabel histori."
                            viewBox={`0 0 ${width} ${184 + labelSpace}`}
                            className="w-full"
                            style={{ minWidth }}
                        >
                            {[0, 25, 50, 75, 100].map((v) => (
                                <g key={v}>
                                    <text
                                        x="40"
                                        y={y(v) + 4}
                                        fill="currentColor"
                                        fontSize="11"
                                        textAnchor="end"
                                    >
                                        {v}%
                                    </text>
                                    <line
                                        x1={plotLeft}
                                        x2={plotRight}
                                        y1={y(v)}
                                        y2={y(v)}
                                        stroke="var(--progress-grid)"
                                    />
                                </g>
                            ))}
                            {(['target', 'actual'] as const).map((key) =>
                                history.map((p, i) => {
                                    const value = p[key],
                                        prev = history[i - 1],
                                        color =
                                            key === 'target'
                                                ? 'var(--progress-plan)'
                                                : 'var(--progress-actual)';
                                    if (value == null) return null;
                                    return (
                                        <g key={key + p.id}>
                                            {prev && prev[key] != null && (
                                                <line
                                                    x1={x(prev)}
                                                    y1={y(Number(prev[key]))}
                                                    x2={x(p)}
                                                    y2={y(Number(value))}
                                                    stroke={color}
                                                    strokeDasharray={
                                                        key === 'target'
                                                            ? '6 4'
                                                            : undefined
                                                    }
                                                    strokeWidth="3"
                                                />
                                            )}
                                            <circle
                                                cx={x(p)}
                                                cy={y(Number(value))}
                                                r="4"
                                                stroke={color}
                                                strokeWidth="2"
                                                fill={
                                                    key === 'actual' &&
                                                    p.partial
                                                        ? 'var(--progress-surface)'
                                                        : color
                                                }
                                            >
                                                <title>
                                                    {p.axis_label}
                                                    {p.axis_label !== p.end &&
                                                        ` (${p.end})`}{' '}
                                                    ·{' '}
                                                    {key === 'actual'
                                                        ? 'Realisasi'
                                                        : 'Rencana'}{' '}
                                                    {percent(value)}
                                                </title>
                                            </circle>
                                        </g>
                                    );
                                }),
                            )}
                            {history.map((period) => (
                                <g
                                    key={period.id}
                                    transform={`translate(${x(period)} 170)`}
                                >
                                    <line
                                        y2="6"
                                        stroke="var(--progress-grid)"
                                    />
                                    <text
                                        transform="translate(-2 14) rotate(45)"
                                        fill="currentColor"
                                        fontSize="12"
                                    >
                                        {period.axis_label}
                                    </text>
                                </g>
                            ))}
                        </svg>
                    </div>
                    <details>
                        <summary className="cursor-pointer">
                            Rincian historis
                        </summary>
                        <table className="[&_td]:border-progress-grid [&_th]:border-progress-grid [&_th]:text-current">
                            <thead>
                                <tr>
                                    <th>Akhir periode</th>
                                    <th>Rencana</th>
                                    <th>Realisasi</th>
                                    <th>Deviasi</th>
                                </tr>
                            </thead>
                            <tbody>
                                {history.map((p) => (
                                    <tr key={p.id}>
                                        <td>{p.end}</td>
                                        <td>{percent(p.target)}</td>
                                        <td>
                                            {percent(p.actual)}{' '}
                                            {p.is_simulated ? '(simulasi)' : ''}
                                            {p.reported_actual != null
                                                ? '(ringkasan proyek)'
                                                : ''}
                                            {p.partial && p.actual != null
                                                ? '(parsial)'
                                                : ''}
                                        </td>
                                        <td>{percent(p.deviation, ' pp')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </details>
                </>
            )}
        </section>
    );
}
