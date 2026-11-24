import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    template,
    previewImport,
    applyImport,
    schedule,
} from '@/routes/projects';
import { workspace } from '@/routes';
import { percent, type Monitoring } from '@/types/monitoring';

export function Planning({ data }: { data: Monitoring }) {
    const blank = { start: '', end: '', target: '' };
    const [rows, setRows] = useState([blank]);
    const [review, setReview] = useState(false);
    const project = data.project!.id;
    return (
        <section className="grid gap-4">
            <details>
                <summary>Susun jadwal baseline</summary>
                <div className="mt-3 grid gap-3">
                    {rows.map((r, i) => (
                        <div className="grid gap-2 sm:grid-cols-4" key={i}>
                            {(['start', 'end', 'target'] as const).map((k) => (
                                <label key={k}>
                                    {
                                        {
                                            start: 'Mulai',
                                            end: 'Akhir',
                                            target: 'Target kumulatif (%)',
                                        }[k]
                                    }
                                    <Input
                                        type={
                                            k === 'target' ? 'number' : 'date'
                                        }
                                        value={r[k]}
                                        onChange={(e) => {
                                            setReview(false);
                                            setRows(
                                                rows.map((v, n) =>
                                                    n === i
                                                        ? {
                                                              ...v,
                                                              [k]: e.target
                                                                  .value,
                                                          }
                                                        : v,
                                                ),
                                            );
                                        }}
                                    />
                                </label>
                            ))}
                            <Button
                                variant="outline"
                                onClick={() => {
                                    setRows(rows.filter((_, n) => n !== i));
                                    setReview(false);
                                }}
                            >
                                Hapus
                            </Button>
                        </div>
                    ))}
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => {
                                setRows([...rows, blank]);
                                setReview(false);
                            }}
                        >
                            Tambah periode
                        </Button>
                        <Button
                            disabled={!rows.length}
                            onClick={() => setReview(true)}
                        >
                            Tinjau revisi
                        </Button>
                    </div>
                    {review && (
                        <div>
                            <p>
                                Revisi {data.revision + 1} mulai {rows[0].start}
                                .{' '}
                                {
                                    data.history.filter(
                                        (p) => p.end >= rows[0].start,
                                    ).length
                                }{' '}
                                periode lama dalam rentang penggantian. Periode
                                dengan realisasi dilindungi.
                            </p>
                            <Button
                                onClick={() =>
                                    router.put(schedule.url(project), {
                                        rows,
                                        revision: data.revision,
                                    })
                                }
                            >
                                Terbitkan jadwal
                            </Button>
                        </div>
                    )}
                </div>
            </details>
            <details open={!!data.importPreview || !!data.importDone}>
                <summary>Impor template Excel</summary>
                <p>
                    Isi kode grup, item dan tahap yang unik. Gunakan tanggal
                    YYYY-MM-DD, serta 1 atau 0 untuk penanda asumsi dan
                    simulasi. Lembar opsional boleh kosong.
                </p>
                <a className="underline" href={template.url()}>
                    Unduh template XLSX
                </a>
                <Form
                    {...previewImport.form(project)}
                    className="my-3 flex flex-wrap items-end gap-2"
                >
                    <label>
                        Workbook
                        <Input
                            name="file"
                            type="file"
                            accept=".xlsx"
                            required
                        />
                    </label>
                    <Button>Tinjau impor</Button>
                </Form>
                {data.importPreview && (
                    <Form {...applyImport.form(project)}>
                        <h3>Hasil rekonsiliasi</h3>
                        <p>
                            {Object.entries(data.importPreview.counts)
                                .map(([k, n]) => k + ' ' + n)
                                .join(' · ')}
                        </p>
                        <input
                            name="token"
                            type="hidden"
                            value={data.importPreview.token}
                        />
                        <Button>Terapkan impor</Button>
                    </Form>
                )}
                {data.importDone && <p role="status">Impor selesai.</p>}
            </details>
        </section>
    );
}
export function Comparison({ data }: { data: Monitoring }) {
    const { period, history, report, comparisonReport } = data;
    if (!period) return null;
    const options = history.filter((p) => p.end < period.start);
    if (!options.length) return null;
    return (
        <section>
            <h2>Perubahan kontribusi</h2>
            <label>
                Periode pembanding
                <select
                    value={data.comparison?.id ?? ''}
                    onChange={(e) =>
                        router.visit(
                            workspace('ringkasan', {
                                query: {
                                    project: data.project!.id,
                                    period: period.id,
                                    compare: e.target.value,
                                },
                            }),
                        )
                    }
                >
                    {options.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.end}
                        </option>
                    ))}
                </select>
            </label>
            {!comparisonReport || period.partial ? (
                <p>
                    Perbandingan memerlukan detail lengkap dengan struktur dan
                    bobot yang sepadan.
                </p>
            ) : (
                report.groups.map((g, i) => {
                    const old = comparisonReport.groups[i];
                    return (
                        <details key={g.id}>
                            <summary>
                                {g.name} ·{' '}
                                {percent(g.contribution ?? null, ' pp')}{' '}
                                sekarang ·{' '}
                                {percent(old.contribution ?? null, ' pp')}{' '}
                                sebelumnya · Δ{' '}
                                {percent(
                                    Number(g.contribution) -
                                        Number(old.contribution),
                                    ' pp',
                                )}
                            </summary>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Sekarang</th>
                                        <th>Sebelumnya</th>
                                        <th>Selisih</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {g.items.map((item, j) => (
                                        <tr key={item.id}>
                                            <td>{item.name}</td>
                                            <td>
                                                {percent(
                                                    item.contribution,
                                                    ' pp',
                                                )}
                                            </td>
                                            <td>
                                                {percent(
                                                    old.items[j].contribution,
                                                    ' pp',
                                                )}
                                            </td>
                                            <td>
                                                {percent(
                                                    Number(item.contribution) -
                                                        Number(
                                                            old.items[j]
                                                                .contribution,
                                                        ),
                                                    ' pp',
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </details>
                    );
                })
            )}
        </section>
    );
}
