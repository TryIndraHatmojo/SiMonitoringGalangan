import { percent, type Period } from '@/types/monitoring';

export function History({ history }: { history: Period[] }) {
    const start = Date.parse(history[0]?.end ?? '');
    const span = Date.parse(history.at(-1)?.end ?? '') - start;
    const x = (period: Period) =>
        span ? 48 + (680 * (Date.parse(period.end) - start)) / span : 388;
    const y = (value: number) => 170 - 1.45 * value;
    return (
        <section className="border-progress-grid bg-progress-surface text-progress-foreground">
            <h2>Kurva S pembangunan</h2>
            {!history.length ? (
                <span>Belum ada periode laporan.</span>
            ) : (
                <>
                    <div className="flex flex-wrap gap-5 text-sm">
                        <span className="text-progress-actual">● Realisasi</span>
                        <span className="text-progress-plan">┄ Rencana</span>
                        {history.some(
                            (p) => p.partial && p.actual !== null,
                        ) && <span>○ Parsial</span>}
                    </div>
                    <svg
                        role="img"
                        aria-label="Kurva S kumulatif menurut tanggal akhir periode. Rincian tersedia dalam tabel histori."
                        viewBox="0 0 755 205"
                        className="w-full"
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
                                    x1="48"
                                    x2="728"
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
                                                key === 'actual' && p.partial
                                                    ? 'var(--progress-surface)'
                                                    : color
                                            }
                                        >
                                            <title>
                                                {p.end} ·{' '}
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
                        <text x="48" y="195" fill="currentColor" fontSize="12">
                            {history[0].end}
                        </text>
                        {history.length > 1 && (
                            <text
                                x="728"
                                y="195"
                                fill="currentColor"
                                fontSize="12"
                                textAnchor="end"
                            >
                                {history.at(-1)?.end}
                            </text>
                        )}
                    </svg>
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
