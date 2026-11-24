import { percent, rupiah, type Monitoring } from '@/types/monitoring';

export function BaselineCharts({ data }: { data: Monitoring }) {
    const { period, report, activeEstimate } = data;
    if (!period) return null;
    const amounts = new Map<string, number>();
    for (const line of activeEstimate?.lines ?? []) {
        const cost =
            Number(line.quantity) *
            (Number(line.local) +
                Number(line.usd) * Number(activeEstimate?.rate));
        amounts.set(line.item, (amounts.get(line.item) ?? 0) + cost);
    }
    return (
        <section>
            <h2>Posisi proyek · {period.end}</h2>
            <div className="my-4 grid gap-4 sm:grid-cols-2">
                {[
                    ['Rencana', period.target],
                    ['Realisasi', period.actual],
                ].map(([label, value]) => (
                    <div key={label}>
                        <strong>{label}</strong>
                        <div className="my-1 h-2 rounded bg-muted">
                            <div
                                className="h-full rounded bg-primary"
                                style={{ width: Number(value ?? 0) + '%' }}
                            />
                        </div>
                        {value == null
                            ? 'Belum tersedia'
                            : percent(Number(value)) +
                              ' selesai · ' +
                              percent(100 - Number(value)) +
                              ' sisa'}
                    </div>
                ))}
            </div>
            {activeEstimate && (
                <>
                    <h3>Bobot biaya per kelompok</h3>
                    {report.groups.map((g) => {
                        const ids = new Set(g.items.map((i) => i.id));
                        const amount = g.items.reduce(
                            (s, i) => s + (amounts.get(i.id) ?? 0),
                            0,
                        );
                        return (
                            <details key={g.id} className="my-3">
                                <summary>
                                    {g.name} · {percent(g.weight)} ·{' '}
                                    {rupiah(amount)}
                                    <span className="mt-1 block h-1.5 rounded bg-muted">
                                        <span
                                            className="block h-full rounded bg-primary"
                                            style={{
                                                width: Number(g.weight) + '%',
                                            }}
                                        />
                                    </span>
                                </summary>
                                <ul>
                                    {activeEstimate.lines
                                        .filter((l) => ids.has(l.item))
                                        .map((l) => (
                                            <li key={l.code}>
                                                {l.code} · {l.name} ·{' '}
                                                {rupiah(
                                                    Number(l.quantity) *
                                                        (Number(l.local) +
                                                            Number(l.usd) *
                                                                Number(
                                                                    activeEstimate.rate,
                                                                )),
                                                )}
                                            </li>
                                        ))}
                                </ul>
                            </details>
                        );
                    })}
                </>
            )}
        </section>
    );
}
