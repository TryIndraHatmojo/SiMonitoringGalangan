import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { workspace } from '@/routes';
import { update } from '@/routes/projects/estimate';
import { money } from '@/types/monitoring';
import type {
    EstimateDraft,
    EstimateInput,
    EstimateLineInput,
    Group,
} from '@/types/monitoring';

const LINE_FIELDS = [
    ['description', 'Uraian', 'text'],
    ['quantity', 'Volume', 'decimal'],
    ['unit', 'Satuan', 'text'],
    ['local_price_idr', 'Harga lokal (Rp)', 'decimal'],
    ['usd_unit_price', 'Harga satuan impor (USD)', 'decimal'],
] as const;

const PAGE_SIZE = 25;

function draftInput(estimate: EstimateDraft | null): EstimateInput {
    return {
        exchange_rate_idr_per_usd: trimDecimal(
            estimate?.exchange_rate_idr_per_usd,
        ),
        exchange_rate_date: estimate?.exchange_rate_date ?? null,
        exchange_rate_is_assumption:
            estimate?.exchange_rate_is_assumption ?? true,
        lines: (estimate?.lines ?? []).map((row) => ({
            id: row.id,
            description: row.description,
            quantity: trimDecimal(row.quantity),
            unit: row.unit,
            local_price_idr: trimDecimal(row.local_price_idr),
            local_price_is_lump_sum: row.local_price_is_lump_sum,
            usd_unit_price: trimDecimal(row.usd_unit_price),
            wbs_item_id: row.wbs_item_id,
        })),
    };
}

function trimDecimal(value: string | null | undefined) {
    return value?.includes('.')
        ? value.replace(/0+$/, '').replace(/\.$/, '')
        : (value ?? null);
}

export function EstimateEditor({
    project,
    estimate,
    groups,
    period,
}: {
    project: number;
    estimate: EstimateDraft | null;
    groups: Group[];
    period?: number;
}) {
    const form = useForm<EstimateInput>(() => draftInput(estimate));
    const nextId = useRef(-1);
    const lineEditor = useRef<HTMLFieldSetElement>(null);
    const [editing, setEditing] = useState<number | null>(null);
    useEffect(() => {
        if (editing !== null) {
            lineEditor.current?.focus();
        }
    }, [editing]);
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState('');
    const [page, setPage] = useState(0);
    const draftById = new Map(estimate?.lines.map((line) => [line.id, line]));
    const items = new Map(
        groups.flatMap((group) =>
            group.items.map(
                (item) => [item.id.toLowerCase(), { item, group }] as const,
            ),
        ),
    );
    const rows = form.data.lines
        .map((line, index) => ({
            line,
            index,
            mapping: items.get(line.wbs_item_id ?? ''),
            source: draftById.get(line.id),
        }))
        .filter(
            ({ line, mapping }) =>
                (!filter || mapping?.group.id === filter) &&
                [line.description, mapping?.item.name].some((value) =>
                    value
                        ?.toLocaleLowerCase('id-ID')
                        .includes(search.trim().toLocaleLowerCase('id-ID')),
                ),
        );
    const lastPage = Math.max(0, Math.ceil(rows.length / PAGE_SIZE) - 1);
    const currentPage = Math.min(page, lastPage);
    const editingIndex = form.data.lines.findIndex(({ id }) => id === editing);
    const editingLine = form.data.lines[editingIndex];

    function changeLine(patch: Partial<EstimateLineInput>) {
        form.setData(
            'lines',
            form.data.lines.map((line) =>
                line.id === editing ? { ...line, ...patch } : line,
            ),
        );
    }

    function addLine() {
        const id = nextId.current--;
        form.setData('lines', [
            ...form.data.lines,
            {
                id,
                description: '',
                quantity: null,
                unit: null,
                local_price_idr: null,
                local_price_is_lump_sum: false,
                usd_unit_price: null,
                wbs_item_id: null,
            },
        ]);
        setEditing(id);
    }

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            lines: data.lines.map((line) => ({
                ...line,
                id: line.id > 0 ? line.id : null,
            })),
        }));
        form.put(update.url(project), {
            preserveScroll: true,
            onSuccess: ({ props }) => {
                const saved = draftInput(
                    props.estimate as EstimateDraft | null,
                );
                form.setData(saved);
                form.setDefaults(saved);
                setEditing(null);
            },
            onError: (errors) => {
                const path = Object.keys(errors).find((key) =>
                    /^lines\.\d+\./.test(key),
                );
                const index = path ? Number(path.split('.')[1]) : -1;
                if (form.data.lines[index]) {
                    setEditing(form.data.lines[index].id);
                }
            },
        });
    }

    return (
        <form onSubmit={save} className="grid min-w-0 gap-4" noValidate>
            <section>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2>Kurs estimasi</h2>
                        <p>Anggaran dasar dihitung sebelum profit galangan.</p>
                    </div>
                    <Button
                        type="submit"
                        disabled={form.processing || !form.isDirty}
                    >
                        {form.processing ? 'Menyimpan…' : 'Simpan draft EE'}
                    </Button>
                </div>
                <fieldset disabled={form.processing}>
                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <label>
                            Kurs (Rp per USD)
                            <Input
                                inputMode="decimal"
                                value={
                                    form.data.exchange_rate_idr_per_usd ?? ''
                                }
                                aria-invalid={
                                    !!form.errors.exchange_rate_idr_per_usd
                                }
                                onChange={(event) =>
                                    form.setData(
                                        'exchange_rate_idr_per_usd',
                                        event.target.value.replace(',', '.'),
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.exchange_rate_idr_per_usd}
                            />
                        </label>
                        <label>
                            Tanggal kurs
                            <Input
                                type="date"
                                value={form.data.exchange_rate_date ?? ''}
                                onChange={(event) =>
                                    form.setData(
                                        'exchange_rate_date',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.exchange_rate_date}
                            />
                        </label>
                    </div>
                    <label className="mt-4 flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.exchange_rate_is_assumption}
                            onChange={(event) =>
                                form.setData(
                                    'exchange_rate_is_assumption',
                                    event.target.checked,
                                )
                            }
                        />
                        Kurs masih asumsi
                    </label>
                    <InputError
                        message={form.errors.exchange_rate_is_assumption}
                    />
                </fieldset>
                <div className="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-3">
                    {[
                        ['Subtotal lokal', money(estimate?.local_idr)],
                        ['Subtotal impor', money(estimate?.import_usd, 'USD')],
                        [
                            'Ekuivalen rupiah (BAC)',
                            money(estimate?.equivalent_idr),
                        ],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <span className="text-sm text-muted-foreground">
                                {label}
                            </span>
                            <strong className="block text-lg tabular-nums">
                                {value}
                            </strong>
                        </div>
                    ))}
                </div>
                <div aria-live="polite">
                    {form.hasErrors && (
                        <p role="alert">
                            Periksa isian estimasi yang ditandai.
                        </p>
                    )}
                    {form.isDirty && (
                        <p>Jumlah diperbarui setelah draft disimpan.</p>
                    )}
                    {form.recentlySuccessful && !form.isDirty && (
                        <p>Draft EE tersimpan.</p>
                    )}
                </div>
            </section>
            <section className="min-w-0">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <h2>Rincian biaya</h2>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={addLine}
                        disabled={form.processing}
                    >
                        Tambah baris
                    </Button>
                </div>
                {groups.length === 0 && (
                    <p>
                        Buat item pekerjaan di{' '}
                        <Link
                            className="underline"
                            href={workspace('builder', {
                                query: { project, period },
                            })}
                        >
                            Form Builder
                        </Link>{' '}
                        untuk memetakan biaya.
                    </p>
                )}
                <InputError message={form.errors.lines} />
                {editingLine && (
                    <fieldset
                        ref={lineEditor}
                        tabIndex={-1}
                        disabled={form.processing}
                        className="my-4 rounded-lg border p-4"
                    >
                        <legend className="px-2 text-sm font-semibold">
                            Baris {editingIndex + 1}
                        </legend>
                        <div className="grid gap-3 sm:grid-cols-2">
                            {LINE_FIELDS.map(([key, label, mode]) => {
                                const error =
                                    form.errors[`lines.${editingIndex}.${key}`];
                                return (
                                    <label key={key}>
                                        {label}
                                        <Input
                                            inputMode={mode}
                                            aria-invalid={!!error}
                                            value={editingLine[key] ?? ''}
                                            onChange={(event) =>
                                                changeLine({
                                                    [key]:
                                                        mode === 'decimal'
                                                            ? event.target.value.replace(
                                                                  ',',
                                                                  '.',
                                                              )
                                                            : event.target
                                                                  .value,
                                                })
                                            }
                                        />
                                        <InputError message={error} />
                                    </label>
                                );
                            })}
                            <label>
                                Item WBS tujuan
                                <select
                                    value={editingLine.wbs_item_id ?? ''}
                                    onChange={(event) =>
                                        changeLine({
                                            wbs_item_id:
                                                event.target.value || null,
                                        })
                                    }
                                >
                                    <option value="">Belum dipetakan</option>
                                    {editingLine.wbs_item_id &&
                                        !items.has(editingLine.wbs_item_id) && (
                                            <option
                                                value={editingLine.wbs_item_id}
                                            >
                                                Item sudah dihapus
                                            </option>
                                        )}
                                    {groups.map((group) => (
                                        <optgroup
                                            key={group.id}
                                            label={
                                                group.name || 'Kelompok baru'
                                            }
                                        >
                                            {group.items.map((item) => (
                                                <option
                                                    key={item.id}
                                                    value={item.id.toLowerCase()}
                                                >
                                                    {item.name || 'Item baru'}
                                                </option>
                                            ))}
                                        </optgroup>
                                    ))}
                                </select>
                                <InputError
                                    message={
                                        form.errors[
                                            `lines.${editingIndex}.wbs_item_id`
                                        ]
                                    }
                                />
                            </label>
                            <label className="flex items-center gap-2 self-end pb-2">
                                <input
                                    type="checkbox"
                                    checked={
                                        editingLine.local_price_is_lump_sum
                                    }
                                    onChange={(event) =>
                                        changeLine({
                                            local_price_is_lump_sum:
                                                event.target.checked,
                                        })
                                    }
                                />
                                Harga lokal berupa total (lump sum)
                            </label>
                        </div>
                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(null)}
                            >
                                Tutup rincian
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    form.setData(
                                        'lines',
                                        form.data.lines.filter(
                                            (line) => line.id !== editing,
                                        ),
                                    );
                                    form.clearErrors();
                                    setEditing(null);
                                }}
                            >
                                Hapus baris
                            </Button>
                        </div>
                    </fieldset>
                )}
                <div className="my-4 grid gap-3 sm:grid-cols-2">
                    <label>
                        Cari uraian atau item WBS
                        <Input
                            type="search"
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setPage(0);
                            }}
                        />
                    </label>
                    <label>
                        Kelompok pekerjaan
                        <select
                            value={filter}
                            onChange={(event) => {
                                setFilter(event.target.value);
                                setPage(0);
                            }}
                        >
                            <option value="">Semua kelompok</option>
                            {groups.map((group) => (
                                <option key={group.id} value={group.id}>
                                    {group.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-lg">
                        <thead>
                            <tr>
                                <th scope="col">Uraian</th>
                                <th scope="col">Item WBS</th>
                                <th scope="col">Ekuivalen tersimpan</th>
                                <th scope="col">
                                    <span className="sr-only">Aksi</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows
                                .slice(
                                    currentPage * PAGE_SIZE,
                                    (currentPage + 1) * PAGE_SIZE,
                                )
                                .map(({ line, index, mapping, source }) => (
                                    <tr key={line.id}>
                                        <td>
                                            {line.description || 'Uraian baru'}
                                        </td>
                                        <td>
                                            {mapping?.item.name ||
                                                'Belum dipetakan'}
                                            {mapping && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {mapping.group.name}
                                                </span>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap tabular-nums">
                                            {money(source?.equivalent_idr)}
                                        </td>
                                        <td>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                disabled={form.processing}
                                                onClick={() =>
                                                    setEditing(line.id)
                                                }
                                                aria-label={
                                                    'Edit ' +
                                                    (line.description ||
                                                        'baris ' + (index + 1))
                                                }
                                            >
                                                Edit
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                        </tbody>
                    </table>
                    {rows.length === 0 && (
                        <p>
                            {form.data.lines.length
                                ? 'Tidak ada baris yang cocok.'
                                : 'Belum ada rincian biaya.'}
                        </p>
                    )}
                </div>
                {lastPage > 0 && (
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2">
                        <span className="text-sm text-muted-foreground">
                            Halaman {currentPage + 1} dari {lastPage + 1}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={currentPage === 0}
                                onClick={() => setPage(currentPage - 1)}
                            >
                                Sebelumnya
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={currentPage === lastPage}
                                onClick={() => setPage(currentPage + 1)}
                            >
                                Berikutnya
                            </Button>
                        </div>
                    </div>
                )}
            </section>
        </form>
    );
}
