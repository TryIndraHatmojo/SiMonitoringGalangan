import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { FormBuilderImport } from '@/components/form-builder-import';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { workspace } from '@/routes';
import {
    formBuilderExample,
    formBuilderTemplate,
    structure,
} from '@/routes/projects';
import type { Group, Item, Weight } from '@/types/monitoring';
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

function amount(value: Weight | undefined) {
    if (value == null || value.toString().trim() === '') {
        return null;
    }

    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : null;
}

function workWeight(value: Weight | undefined) {
    return percent(value, '%', 6);
}

function tonnage(value: number) {
    return value.toLocaleString('id-ID', { maximumFractionDigits: 6 }) + ' ton';
}

function requestWeight(value: Weight | undefined) {
    return typeof value === 'number' ? value.toFixed(6) : value;
}

function DecimalInput({
    value,
    onChange,
}: {
    value: Weight | undefined;
    onChange: (value: string) => void;
}) {
    return (
        <Input
            inputMode="decimal"
            value={value ?? ''}
            onChange={(event) => onChange(event.target.value.replace(',', '.'))}
        />
    );
}

export function WbsEditor({
    project,
    draft,
    revision,
    hasEstimate,
    estimateWeights,
    canImportFormBuilder,
}: {
    project: number;
    draft: Group[];
    revision: number;
    hasEstimate: boolean;
    estimateWeights: Record<string, number> | null;
    canImportFormBuilder: boolean;
}) {
    const [groups, setGroups] = useState(draft);
    const [index, select] = useState(0);
    const [busy, setBusy] = useState(false);
    const group = groups[index];
    const construction = group?.type === 'construction';
    const totalBlockWeight = construction
        ? group.items.reduce(
              (sum, block) => sum + (amount(block.weight_tons) ?? 0),
              0,
          )
        : 0;
    function change(data: Partial<Group>) {
        setGroups(groups.map((g, i) => (i === index ? { ...g, ...data } : g)));
    }
    function save(publish: boolean) {
        const inputGroups = groups.map((workGroup) => {
            const isConstruction = workGroup.type === 'construction';
            return {
                id: workGroup.id,
                name: workGroup.name,
                type: workGroup.type,
                ...(isConstruction && {
                    manual_project_weight: requestWeight(
                        workGroup.manual_project_weight,
                    ),
                }),
                items: workGroup.items.map(
                    ({ id, name, weight, weight_tons }) => ({
                        id,
                        name,
                        ...(isConstruction
                            ? { weight_tons }
                            : { weight: requestWeight(weight) }),
                    }),
                ),
                stages: workGroup.stages.map((stage) => ({
                    ...stage,
                    weight: requestWeight(stage.weight),
                })),
            };
        });
        router.put(
            structure.url(project),
            { groups: inputGroups, publish },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onSuccess: (page) => setGroups(page.props.draft as Group[]),
                onFinish: () => setBusy(false),
            },
        );
    }
    function itemWeight(item: Item) {
        return hasEstimate
            ? (estimateWeights?.['item:' + item.id.toLowerCase()] ?? null)
            : amount(item.weight);
    }
    function groupWeight(workGroup: Group) {
        if (workGroup.type === 'construction') {
            return hasEstimate
                ? (estimateWeights?.['group:' + workGroup.id.toLowerCase()] ??
                      null)
                : amount(workGroup.manual_project_weight);
        }
        const weights = workGroup.items.map(itemWeight);
        return weights.some((value) => value === null)
            ? null
            : weights.reduce<number>((sum, value) => sum + (value ?? 0), 0);
    }
    function editRow(
        key: 'items' | 'stages',
        rowIndex: number,
        patch: Partial<Item>,
    ) {
        change({
            [key]: group[key].map((row, i) =>
                i === rowIndex ? { ...row, ...patch } : row,
            ),
        });
    }
    const projectWeights = groups.map(groupWeight);
    const totalProjectWeight = projectWeights.some((value) => value === null)
        ? null
        : projectWeights.reduce<number>((sum, value) => sum + (value ?? 0), 0);
    const selectedGroupWeight = projectWeights[index] ?? null;
    const savedConstruction = draft.find(
        (saved) => saved.id === group?.id && saved.type === 'construction',
    );
    const showPublishedWeights =
        construction &&
        savedConstruction?.project_weight != null &&
        amount(savedConstruction.project_weight) === selectedGroupWeight &&
        savedConstruction.items.length === group.items.length &&
        savedConstruction.items.every(
            (block, i) =>
                block.id === group.items[i].id &&
                amount(block.weight_tons) ===
                    amount(group.items[i].weight_tons),
        );

    return (
        <>
            <div className="flex flex-wrap items-center justify-end gap-2">
                <Button asChild variant="outline">
                    <a href={formBuilderTemplate.url()} download>
                        Unduh Template Excel
                    </a>
                </Button>
                <Button asChild variant="outline">
                    <a href={formBuilderExample.url()} download>
                        Unduh Contoh Template Excel Terisi
                    </a>
                </Button>
                <FormBuilderImport
                    project={project}
                    disabled={
                        !canImportFormBuilder || groups.length > 0 || busy
                    }
                    onImported={(importedGroups) => {
                        setGroups(importedGroups);
                        select(0);
                    }}
                />
                {(!canImportFormBuilder || groups.length > 0) && (
                    <p className="basis-full text-right text-sm text-muted-foreground">
                        Impor tersedia saat draft Form Builder dan EE kosong.
                    </p>
                )}
            </div>
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
                                {g.items.length}{' '}
                                {g.type === 'construction' ? 'blok' : 'item'}
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
                        <div className="grid min-w-0 gap-1.5">
                            <label htmlFor="wbs-group-type">
                                Tipe kelompok
                            </label>
                            <Combobox
                                id="wbs-group-type"
                                label="Tipe kelompok"
                                options={[
                                    { value: 'standard', label: 'Standar' },
                                    {
                                        value: 'construction',
                                        label: 'Konstruksi',
                                    },
                                ]}
                                value={group.type ?? 'standard'}
                                onValueChange={(value) => {
                                    if (
                                        value === 'standard' ||
                                        value === 'construction'
                                    ) {
                                        change({ type: value });
                                    }
                                }}
                            />
                        </div>
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
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            {construction && (
                                <p>
                                    Total berat kapal{' '}
                                    <strong>{tonnage(totalBlockWeight)}</strong>
                                </p>
                            )}
                            {construction && !hasEstimate ? (
                                <label>
                                    Bobot proyek kelompok (%)
                                    <DecimalInput
                                        value={group.manual_project_weight}
                                        onChange={(manual_project_weight) =>
                                            change({ manual_project_weight })
                                        }
                                    />
                                </label>
                            ) : (
                                <p>
                                    Bobot proyek kelompok{' '}
                                    <strong>
                                        {workWeight(selectedGroupWeight)}
                                    </strong>
                                </p>
                            )}
                        </div>
                        {construction && hasEstimate && (
                            <p className="mt-2 text-sm text-muted-foreground">
                                {savedConstruction ? (
                                    <>
                                        Petakan biaya langsung ke kelompok ini
                                        di{' '}
                                        <Link
                                            className="underline"
                                            href={workspace('estimasi', {
                                                query: { project },
                                            })}
                                        >
                                            Engineering Estimate
                                        </Link>
                                        .
                                    </>
                                ) : (
                                    'Simpan draft untuk memilih kelompok ini saat memetakan biaya EE.'
                                )}
                            </p>
                        )}
                        {(['items', 'stages'] as const).map((key) => (
                            <div key={key} className="mt-4 border-t pt-4">
                                <h2>
                                    {key === 'items'
                                        ? construction
                                            ? 'Daftar blok'
                                            : 'Item pekerjaan'
                                        : 'Tahap pengukuran'}
                                </h2>
                                {(key === 'stages' || !construction) && (
                                    <p>
                                        {key === 'items'
                                            ? hasEstimate
                                                ? 'Saat baseline terbit, bobot item dihitung dari biaya pada Engineering Estimate.'
                                                : 'Bobot item terhadap seluruh proyek.'
                                            : construction
                                              ? 'Semua blok dalam kelompok memakai tahap ini.'
                                              : 'Semua item dalam kelompok memakai tahap ini.'}
                                    </p>
                                )}
                                {group[key].map((row: Item, i) => {
                                    const blockWeight = amount(row.weight_tons);
                                    const projectWeight = itemWeight(row);
                                    const inGroup = showPublishedWeights
                                        ? amount(row.group_weight)
                                        : construction
                                          ? totalBlockWeight > 0 &&
                                            blockWeight !== null
                                              ? (100 * blockWeight) /
                                                totalBlockWeight
                                              : null
                                          : selectedGroupWeight &&
                                              projectWeight !== null
                                            ? (100 * projectWeight) /
                                              selectedGroupWeight
                                            : null;
                                    const inProject = showPublishedWeights
                                        ? amount(row.weight)
                                        : construction
                                          ? inGroup !== null &&
                                            selectedGroupWeight !== null
                                              ? (inGroup *
                                                    selectedGroupWeight) /
                                                100
                                              : null
                                          : projectWeight;
                                    return (
                                        <div
                                            key={row.id}
                                            className="my-3 flex flex-wrap items-end gap-2 border-b pb-3"
                                        >
                                            <label className="min-w-36 flex-1">
                                                {key === 'items' && construction
                                                    ? 'Nama blok'
                                                    : 'Nama'}
                                                <Input
                                                    value={row.name ?? ''}
                                                    placeholder={
                                                        key === 'items'
                                                            ? construction
                                                                ? 'Mis. 104 (DB)'
                                                                : 'Mis. Basic Design'
                                                            : 'Mis. Owner Approval'
                                                    }
                                                    onChange={(e) =>
                                                        editRow(key, i, {
                                                            name: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            </label>
                                            {key === 'items' &&
                                                construction && (
                                                    <label className="w-32">
                                                        Berat (ton)
                                                        <DecimalInput
                                                            value={
                                                                row.weight_tons
                                                            }
                                                            onChange={(
                                                                weight_tons,
                                                            ) =>
                                                                editRow(
                                                                    key,
                                                                    i,
                                                                    {
                                                                        weight_tons,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </label>
                                                )}
                                            {(key === 'stages' ||
                                                (!construction &&
                                                    !hasEstimate)) && (
                                                <label className="w-32">
                                                    {key === 'items'
                                                        ? 'Bobot dalam proyek (%)'
                                                        : 'Bobot tahap (%)'}
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        max="100"
                                                        step="any"
                                                        value={row.weight ?? ''}
                                                        onChange={(e) =>
                                                            editRow(key, i, {
                                                                weight: e.target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                </label>
                                            )}
                                            {key === 'items' && (
                                                <dl className="flex flex-wrap gap-x-3 text-sm">
                                                    <div>
                                                        <dt>
                                                            Bobot dalam kelompok
                                                        </dt>
                                                        <dd>
                                                            {workWeight(
                                                                inGroup,
                                                            )}
                                                        </dd>
                                                    </div>
                                                    {(construction ||
                                                        hasEstimate) && (
                                                        <div>
                                                            <dt>
                                                                Bobot dalam
                                                                proyek
                                                            </dt>
                                                            <dd>
                                                                {workWeight(
                                                                    inProject,
                                                                )}
                                                            </dd>
                                                        </div>
                                                    )}
                                                </dl>
                                            )}
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
                                    +{' '}
                                    {key === 'items'
                                        ? construction
                                            ? 'Blok'
                                            : 'Item'
                                        : 'Tahap'}
                                </Button>
                            </div>
                        ))}
                    </section>
                )}
            </div>
            <section className="flex flex-wrap items-center gap-3">
                <strong className="mr-auto">
                    Bobot proyek {workWeight(totalProjectWeight)} · Tahap
                    kelompok{' '}
                    {workWeight(
                        group?.stages.reduce(
                            (sum, stage) => sum + Number(stage.weight ?? 0),
                            0,
                        ) ?? 0,
                    )}
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
