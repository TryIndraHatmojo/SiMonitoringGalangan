import { Form, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { MouseEvent, PointerEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { workspace } from '@/routes';
import { update as updateArea } from '@/routes/projects/blockAreas';
import { destroy, store } from '@/routes/projects/sideView';
import type { BlockAreas, MapBlock, Point, SideView } from '@/types/monitoring';

type Editing = {
    previous: string | null;
    item: string;
    points: Point[];
    drawing: boolean;
};

function editingFor(block: string, areas: BlockAreas): Editing {
    const points = areas[block];

    return {
        previous: points ? block : null,
        item: block,
        points: points ?? [],
        drawing: !points,
    };
}

/** Pointer position normalized to 0–1 of the drawing. */
function pointAt(event: MouseEvent, svg: SVGSVGElement): Point {
    const box = svg.getBoundingClientRect();
    const clamp = (value: number) =>
        Number(Math.min(1, Math.max(0, value)).toFixed(6));

    return [
        clamp((event.clientX - box.left) / box.width),
        clamp((event.clientY - box.top) / box.height),
    ];
}

export function BlockMapEditor({
    project,
    sideView,
    blocks,
    areas,
}: {
    project: number;
    sideView: SideView | null;
    blocks: MapBlock[];
    areas: BlockAreas;
}) {
    const [editing, setEditing] = useState<Editing | null>(() => {
        const requested = new URLSearchParams(
            typeof window === 'undefined' ? '' : window.location.search,
        ).get('block');
        const block = blocks.find((block) => block.id === requested);

        return block ? editingFor(block.id, areas) : null;
    });
    const [zoom, setZoom] = useState('100');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const names = new Map(blocks.map((block) => [block.id, block.name]));

    function send(data: {
        item: string;
        previous?: string | null;
        points: Point[] | null;
    }) {
        router.put(updateArea.url(project), data, {
            preserveScroll: true,
            onStart: () => {
                setBusy(true);
                setError('');
            },
            onSuccess: () => setEditing(null),
            onError: (errors) => setError(Object.values(errors)[0] ?? ''),
            onFinish: () => setBusy(false),
        });
    }

    function save() {
        if (!editing) {
            return;
        }

        if (
            editing.item !== editing.previous &&
            areas[editing.item] &&
            !window.confirm(
                `Blok ${names.get(editing.item)} sudah punya area. Ganti dengan area ini?`,
            )
        ) {
            return;
        }

        send({
            item: editing.item,
            previous: editing.previous,
            points: editing.points,
        });
    }

    function movePoint(event: PointerEvent<SVGCircleElement>, moved: number) {
        const svg = event.currentTarget.ownerSVGElement;

        if (!svg || !event.currentTarget.hasPointerCapture(event.pointerId)) {
            return;
        }

        const point = pointAt(event, svg);
        setEditing(
            (current) =>
                current && {
                    ...current,
                    points: current.points.map((existing, index) =>
                        index === moved ? point : existing,
                    ),
                },
        );
    }

    const upload = (
        <section>
            <h2>Gambar side view kapal</h2>
            <p>
                Unggah satu gambar side view penuh kapal. Format PNG, JPG, atau
                WEBP, maksimal 10 MB.
            </p>
            <Form
                {...store.form(project)}
                className="flex flex-wrap items-end gap-3"
                options={{ preserveScroll: true }}
                resetOnSuccess
            >
                {({ errors, processing }) => (
                    <>
                        <label className="min-w-0 flex-1">
                            File gambar
                            <Input
                                name="image"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                required
                            />
                        </label>
                        <Button disabled={processing}>
                            {sideView ? 'Ganti gambar' : 'Unggah gambar'}
                        </Button>
                        {sideView && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            'Hapus gambar side view beserta semua area blok?',
                                        )
                                    ) {
                                        router.delete(destroy.url(project), {
                                            preserveScroll: true,
                                            onSuccess: () => setEditing(null),
                                        });
                                    }
                                }}
                            >
                                Hapus gambar
                            </Button>
                        )}
                        <InputError
                            className="basis-full"
                            message={errors.image}
                        />
                        {sideView && (
                            <p className="basis-full">
                                Saat gambar diganti, area blok tetap disimpan.
                                Periksa kembali posisinya.
                            </p>
                        )}
                    </>
                )}
            </Form>
        </section>
    );

    if (!sideView) {
        return upload;
    }

    const { width, height } = sideView;
    const scale = (points: Point[]) =>
        points.map(([x, y]) => `${x * width},${y * height}`).join(' ');
    const handleRadius = (width * 0.005 * 100) / Number(zoom);

    return (
        <>
            {upload}
            <section className="min-w-0">
                <h2>Petakan area blok</h2>
                {!blocks.length && (
                    <p>
                        Belum ada blok tersimpan. Buat kelompok Konstruksi dan
                        daftar blok di{' '}
                        <Link
                            className="underline"
                            href={workspace('builder', { query: { project } })}
                        >
                            Form Builder
                        </Link>
                        .
                    </p>
                )}
                <div className="mb-3 flex flex-wrap items-center gap-3">
                    <Button
                        disabled={!blocks.length || busy}
                        onClick={() =>
                            setEditing({
                                previous: null,
                                item: '',
                                points: [],
                                drawing: true,
                            })
                        }
                    >
                        Gambar area baru
                    </Button>
                    <div className="ml-auto w-40">
                        <Combobox
                            label="Perbesaran"
                            value={zoom}
                            onValueChange={setZoom}
                            options={['100', '200', '300'].map((value) => ({
                                value,
                                label: `Zoom ${value}%`,
                            }))}
                        />
                    </div>
                </div>
                <p>
                    {editing?.drawing
                        ? 'Klik gambar untuk menambah titik (minimal 3), lalu geser titik untuk merapikan.'
                        : editing
                          ? 'Geser titik untuk mengubah bentuk, atau gambar ulang area.'
                          : 'Klik area yang sudah ada untuk mengubahnya, atau gambar area baru.'}
                </p>
                <div className="max-h-[70vh] overflow-auto rounded-md border bg-white">
                    <svg
                        viewBox={`0 0 ${width} ${height}`}
                        style={{ width: `${zoom}%` }}
                        className={`block h-auto max-w-none select-none ${editing?.drawing ? 'cursor-crosshair' : ''}`}
                        onClick={(event) => {
                            if (editing?.drawing) {
                                const point = pointAt(
                                    event,
                                    event.currentTarget,
                                );
                                setEditing({
                                    ...editing,
                                    points: [...editing.points, point],
                                });
                            }
                        }}
                    >
                        <image
                            href={sideView.url}
                            width={width}
                            height={height}
                        />
                        {blocks
                            .filter(
                                (block) =>
                                    areas[block.id] &&
                                    block.id !== editing?.previous,
                            )
                            .map((block) => (
                                <polygon
                                    key={block.id}
                                    points={scale(areas[block.id])}
                                    className="fill-sky-500/25 stroke-sky-700 hover:fill-sky-500/45"
                                    strokeWidth={1.5}
                                    vectorEffect="non-scaling-stroke"
                                    onClick={() => {
                                        // While drawing, the click bubbles up and adds a point.
                                        if (!editing?.drawing) {
                                            setEditing(
                                                editingFor(block.id, areas),
                                            );
                                        }
                                    }}
                                >
                                    <title>{block.name}</title>
                                </polygon>
                            ))}
                        {editing && (
                            <>
                                {editing.points.length >= 3 ? (
                                    <polygon
                                        points={scale(editing.points)}
                                        className="fill-fuchsia-500/30 stroke-fuchsia-700"
                                        strokeWidth={2}
                                        vectorEffect="non-scaling-stroke"
                                    />
                                ) : (
                                    <polyline
                                        points={scale(editing.points)}
                                        className="fill-none stroke-fuchsia-700"
                                        strokeWidth={2}
                                        vectorEffect="non-scaling-stroke"
                                    />
                                )}
                                {editing.points.map(([x, y], index) => (
                                    <circle
                                        key={index}
                                        cx={x * width}
                                        cy={y * height}
                                        r={handleRadius}
                                        className="cursor-move touch-none fill-white stroke-fuchsia-700"
                                        strokeWidth={2}
                                        vectorEffect="non-scaling-stroke"
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                        onPointerDown={(event) =>
                                            event.currentTarget.setPointerCapture(
                                                event.pointerId,
                                            )
                                        }
                                        onPointerMove={(event) =>
                                            movePoint(event, index)
                                        }
                                    />
                                ))}
                            </>
                        )}
                    </svg>
                </div>
                <InputError className="mt-2" message={error} />
                {editing && (
                    <div className="mt-3 grid gap-3">
                        <div className="grid max-w-md min-w-0 gap-1.5">
                            <label htmlFor="area-block">
                                Blok pada area ini
                            </label>
                            <Combobox
                                id="area-block"
                                label="Blok pada area ini"
                                placeholder="Pilih blok"
                                value={editing.item}
                                onValueChange={(item) =>
                                    setEditing({ ...editing, item })
                                }
                                options={blocks.map((block) => ({
                                    value: block.id,
                                    label:
                                        block.name +
                                        (areas[block.id]
                                            ? ' (terpetakan)'
                                            : ''),
                                    group: block.group,
                                }))}
                            />
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                disabled={
                                    busy ||
                                    !editing.item ||
                                    editing.points.length < 3
                                }
                                onClick={save}
                            >
                                Simpan area
                            </Button>
                            <Button
                                variant="outline"
                                disabled={!editing.points.length}
                                onClick={() =>
                                    setEditing({
                                        ...editing,
                                        drawing: true,
                                        points: editing.points.slice(0, -1),
                                    })
                                }
                            >
                                Hapus titik terakhir
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setEditing({
                                        ...editing,
                                        drawing: true,
                                        points: [],
                                    })
                                }
                            >
                                Gambar ulang
                            </Button>
                            {editing.previous && (
                                <Button
                                    variant="destructive"
                                    disabled={busy}
                                    onClick={() => {
                                        if (
                                            editing.previous &&
                                            window.confirm(
                                                `Hapus area blok ${names.get(editing.previous)}?`,
                                            )
                                        ) {
                                            send({
                                                item: editing.previous,
                                                points: null,
                                            });
                                        }
                                    }}
                                >
                                    Hapus area
                                </Button>
                            )}
                            <Button
                                variant="ghost"
                                onClick={() => setEditing(null)}
                            >
                                Batal
                            </Button>
                        </div>
                    </div>
                )}
            </section>
            {blocks.length > 0 && (
                <section>
                    <h2>Daftar blok</h2>
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {blocks.map((block) => (
                            <li key={block.id}>
                                <button
                                    type="button"
                                    aria-pressed={editing?.item === block.id}
                                    className={`w-full rounded-md border p-3 text-left text-sm wrap-anywhere ${editing?.item === block.id ? 'bg-accent text-accent-foreground' : ''}`}
                                    onClick={() =>
                                        setEditing(editingFor(block.id, areas))
                                    }
                                >
                                    <strong className="block">
                                        {block.name}
                                    </strong>
                                    {block.group} ·{' '}
                                    {areas[block.id]
                                        ? 'Terpetakan'
                                        : 'Belum dipetakan'}
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </>
    );
}
