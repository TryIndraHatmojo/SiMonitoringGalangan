import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { workspace } from '@/routes';
import {
    blockStatuses,
    blockStatusOf,
    percent,
    type BlockAreas,
    type BlockProgress,
    type MapBlock,
    type SideView,
} from '@/types/monitoring';

export function ShipSideView({
    project,
    sideView,
    blocks,
    areas,
    status,
}: {
    project: number;
    sideView: SideView | null;
    blocks: MapBlock[];
    areas: BlockAreas;
    status: BlockProgress;
}) {
    const [selectedId, select] = useState('');
    const mapped = blocks.filter((block) => areas[block.id]);
    const selected = mapped.find((block) => block.id === selectedId);
    const statusOf = (block: MapBlock) =>
        blockStatusOf(status[block.id]?.progress);

    return (
        <section className="min-w-0">
            <h2>Layout side view kapal</h2>
            {!sideView || !mapped.length ? (
                <p>
                    {sideView
                        ? 'Belum ada area blok yang dipetakan. '
                        : 'Belum ada gambar side view. '}
                    <Link
                        className="font-medium text-primary underline"
                        href={workspace('peta', { query: { project } })}
                    >
                        Buka Peta Blok
                    </Link>
                </p>
            ) : (
                <>
                    <div className="overflow-x-auto rounded-md border bg-white">
                        <svg
                            viewBox={`0 0 ${sideView.width} ${sideView.height}`}
                            className="block h-auto w-full min-w-[40rem]"
                            role="group"
                            aria-label="Side view kapal dengan status realisasi blok"
                        >
                            <image
                                href={sideView.url}
                                width={sideView.width}
                                height={sideView.height}
                            />
                            {mapped.map((block) => {
                                const { label, shape } =
                                    blockStatuses[statusOf(block)];
                                const description = `${block.name}, ${label}, ${percent(status[block.id]?.progress)}`;

                                return (
                                    <polygon
                                        key={block.id}
                                        points={areas[block.id]
                                            .map(
                                                ([x, y]) =>
                                                    `${x * sideView.width},${y * sideView.height}`,
                                            )
                                            .join(' ')}
                                        className={`cursor-pointer outline-none hover:brightness-90 focus-visible:stroke-blue-600 ${shape}`}
                                        strokeWidth={
                                            block.id === selectedId ? 4 : 1.5
                                        }
                                        vectorEffect="non-scaling-stroke"
                                        role="button"
                                        tabIndex={0}
                                        aria-label={description}
                                        aria-pressed={block.id === selectedId}
                                        onClick={() => select(block.id)}
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === 'Enter' ||
                                                event.key === ' '
                                            ) {
                                                event.preventDefault();
                                                select(block.id);
                                            }
                                        }}
                                    >
                                        <title>{description}</title>
                                    </polygon>
                                );
                            })}
                        </svg>
                    </div>
                    <div
                        className="mt-3 rounded-md border p-3"
                        aria-live="polite"
                    >
                        {selected ? (
                            <dl className="grid gap-3 text-sm sm:grid-cols-3">
                                <div>
                                    <dt className="text-muted-foreground">
                                        Nama blok
                                    </dt>
                                    <dd className="font-semibold wrap-anywhere">
                                        {selected.name}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Status
                                    </dt>
                                    <dd className="font-semibold">
                                        {
                                            blockStatuses[statusOf(selected)]
                                                .label
                                        }
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Realisasi terakhir
                                    </dt>
                                    <dd className="font-semibold">
                                        {percent(status[selected.id]?.progress)}
                                        {status[selected.id] && (
                                            <span className="block font-normal text-muted-foreground">
                                                Periode{' '}
                                                {
                                                    status[selected.id]
                                                        .period_label
                                                }
                                            </span>
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        ) : (
                            <span className="text-sm text-muted-foreground">
                                Pilih blok pada gambar untuk melihat detail.
                            </span>
                        )}
                    </div>
                    <h3 className="mt-4 mb-2 text-sm font-semibold">
                        Legenda status
                    </h3>
                    <ul className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        {Object.entries(blockStatuses).map(([key, item]) => (
                            <li key={key} className="flex items-start gap-2">
                                <span
                                    aria-hidden="true"
                                    className={`mt-0.5 size-4 shrink-0 rounded border ${item.swatch}`}
                                />
                                <span>
                                    <strong>
                                        {item.label} (
                                        {
                                            mapped.filter(
                                                (block) =>
                                                    statusOf(block) === key,
                                            ).length
                                        }{' '}
                                        blok)
                                    </strong>
                                    <span className="block text-muted-foreground">
                                        {item.description}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
