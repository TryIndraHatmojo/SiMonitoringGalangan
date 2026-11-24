import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { structure } from '@/routes/projects';
import type { Group, Item, WorkRow } from '@/types/monitoring';
import { percent } from '@/types/monitoring';

function newId() {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join(
        '',
    );
    return [
        hex.slice(0, 8),
        hex.slice(8, 12),
        hex.slice(12, 16),
        hex.slice(16, 20),
        hex.slice(20),
    ].join('-');
}
export function WbsEditor({
    project,
    draft,
    revision,
}: {
    project: number;
    draft: Group[];
    revision: number;
}) {
    const [groups, setGroups] = useState(draft);
    const [index, select] = useState(0);
    const [busy, setBusy] = useState(false);
    const group = groups[index];
    function change(data: Partial<Group>) {
        setGroups(groups.map((g, i) => (i === index ? { ...g, ...data } : g)));
    }
    function save(publish: boolean) {
        router.put(
            structure.url(project),
            { groups, publish },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    }
    const sum = (rows: WorkRow[]) =>
        rows.reduce((n, row) => n + Number(row.weight), 0);
    return (
        <>
            <div className="grid items-start gap-4 lg:grid-cols-[15rem_1fr]">
                <section>
                    <h2>Kelompok kerja</h2>
                    {groups.map((g, i) => (
                        <button
                            key={g.id}
                            className={
                                'block w-full border-b p-3 text-left ' +
                                (i === index
                                    ? 'bg-accent text-accent-foreground'
                                    : '')
                            }
                            onClick={() => select(i)}
                        >
                            {g.name || 'Kelompok baru'}
                            <small className="block">
                                {g.items.length} item
                            </small>
                        </button>
                    ))}
                    <Button
                        variant="outline"
                        onClick={() => {
                            select(groups.length);
                            setGroups([
                                ...groups,
                                {
                                    id: newId(),
                                    name: '',
                                    items: [],
                                    stages: [],
                                },
                            ]);
                        }}
                    >
                        + Kelompok
                    </Button>
                </section>
                {group && (
                    <section>
                        <label>
                            Nama kelompok
                            <Input
                                value={group.name ?? ''}
                                placeholder="Mis. Design & Approval Drawing"
                                onChange={(e) =>
                                    change({ name: e.target.value })
                                }
                            />
                        </label>
                        <Button
                            variant="ghost"
                            onClick={() => {
                                setGroups(groups.filter((_, i) => i !== index));
                                select(0);
                            }}
                        >
                            Hapus kelompok
                        </Button>
                        {(['items', 'stages'] as const).map((key) => (
                            <div key={key} className="mt-4 border-t pt-4">
                                <h2>
                                    {key === 'items'
                                        ? 'Item pekerjaan'
                                        : 'Tahap pengukuran'}
                                </h2>
                                <p>
                                    {key === 'items'
                                        ? 'Bobot item terhadap seluruh proyek.'
                                        : 'Semua item dalam kelompok memakai tahap ini.'}
                                </p>
                                {group[key].map((row, i) => {
                                    function edit(patch: Partial<Item>) {
                                        change({
                                            [key]: group[key].map((r, j) =>
                                                i === j
                                                    ? { ...r, ...patch }
                                                    : r,
                                            ),
                                        });
                                    }
                                    return (
                                        <div
                                            key={row.id}
                                            className="my-2 grid grid-cols-[minmax(0,1fr)_auto] items-end gap-2 lg:grid-cols-[minmax(0,1fr)_6rem_auto]"
                                        >
                                            <label className="col-span-2 lg:col-span-1">
                                                Nama
                                                <Input
                                                    value={row.name ?? ''}
                                                    placeholder={
                                                        key === 'items'
                                                            ? 'Mis. Basic Design'
                                                            : 'Mis. Owner Approval'
                                                    }
                                                    onChange={(e) =>
                                                        edit({
                                                            name: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </label>
                                            {key === 'items' && (
                                                <label>
                                                    Template tahap
                                                    <select
                                                        value={
                                                            (row as Item)
                                                                .template_name ??
                                                            ''
                                                        }
                                                        onChange={(e) => {
                                                            const name =
                                                                e.target.value;
                                                            const source =
                                                                groups.find(
                                                                    (g) =>
                                                                        g.id ===
                                                                        name,
                                                                );
                                                            const stages =
                                                                source?.stages ?? [
                                                                    {
                                                                        id: newId(),
                                                                        name: 'Pemeriksaan',
                                                                        weight: 50,
                                                                    },
                                                                    {
                                                                        id: newId(),
                                                                        name: 'Pengujian',
                                                                        weight: 50,
                                                                    },
                                                                ];
                                                            edit({
                                                                template_name:
                                                                    name ||
                                                                    undefined,
                                                                stages: name
                                                                    ? stages.map(
                                                                          (
                                                                              s,
                                                                          ) => ({
                                                                              ...s,
                                                                          }),
                                                                      )
                                                                    : undefined,
                                                            });
                                                        }}
                                                    >
                                                        <option value="">
                                                            Tahap kelompok
                                                        </option>
                                                        <option value="inspection">
                                                            Pemeriksaan dan
                                                            pengujian
                                                        </option>
                                                        {groups
                                                            .filter(
                                                                (g) =>
                                                                    g.stages
                                                                        .length >
                                                                    0,
                                                            )
                                                            .map((g) => (
                                                                <option
                                                                    key={g.id}
                                                                    value={g.id}
                                                                >
                                                                    {g.name}
                                                                </option>
                                                            ))}
                                                    </select>
                                                </label>
                                            )}
                                            <label>
                                                Bobot (%)
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    step="any"
                                                    value={row.weight ?? ''}
                                                    placeholder={
                                                        key === 'items'
                                                            ? '0.5831'
                                                            : '30'
                                                    }
                                                    onChange={(e) =>
                                                        edit({
                                                            weight: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </label>
                                            <Button
                                                aria-label={'Hapus ' + row.name}
                                                variant="outline"
                                                onClick={() =>
                                                    change({
                                                        [key]: group[
                                                            key
                                                        ].filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    })
                                                }
                                            >
                                                ×
                                            </Button>
                                        </div>
                                    );
                                })}
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        change({
                                            [key]: [
                                                ...group[key],
                                                {
                                                    id: newId(),
                                                    name: '',
                                                    weight: '',
                                                },
                                            ],
                                        })
                                    }
                                >
                                    + {key === 'items' ? 'Item' : 'Tahap'}
                                </Button>
                            </div>
                        ))}
                    </section>
                )}
            </div>
            <section className="flex flex-wrap items-center gap-3">
                <strong className="mr-auto">
                    Bobot proyek{' '}
                    {percent(groups.reduce((n, g) => n + sum(g.items), 0))} ·
                    Tahap kelompok {percent(group ? sum(group.stages) : 0)}
                </strong>
                <Button
                    variant="outline"
                    disabled={busy}
                    onClick={() => save(false)}
                >
                    Simpan draft
                </Button>
                <Button disabled={busy} onClick={() => save(true)}>
                    Tetapkan baseline
                </Button>
            </section>
            <p>Revisi baseline aktif: {revision || 'Belum ditetapkan'}</p>
        </>
    );
}
