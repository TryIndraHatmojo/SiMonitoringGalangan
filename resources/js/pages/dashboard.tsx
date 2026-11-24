import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useId, useState } from 'react';
import { EstimateEditor } from '@/components/estimate-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { History } from '@/components/progress-history';
import { WbsEditor } from '@/components/wbs-editor';
import { workspace } from '@/routes';
import { store, period as createPeriod, edit, update } from '@/routes/projects';
import {
    money,
    percent,
    sections,
    type Monitoring,
    type Group,
    type Period,
} from '@/types/monitoring';

const targetPlaceholder = '98.5';

function DateField({
    name,
    label,
    example,
}: {
    name: 'start' | 'end';
    label: string;
    example: string;
}) {
    const hintId = useId();

    return (
        <div className="grid gap-1">
            <label>
                {label}
                <Input
                    name={name}
                    required
                    type="date"
                    aria-describedby={hintId}
                />
            </label>
            <span id={hintId} className="text-xs text-muted-foreground">
                Mis. {example}
            </span>
        </div>
    );
}

function ProjectForm({ projects }: Pick<Monitoring, 'projects'>) {
    return (
        <section>
            <h2>Identitas proyek kapal</h2>
            <Form
                {...store.form()}
                className="grid items-start gap-4 sm:grid-cols-2"
            >
                {[
                    ['code', 'Kode pembangunan', 'Mis. ASD-TUG-002'],
                    ['name', 'Nama kapal', 'Mis. ASD Harbour Tug 2 x 1800 HP'],
                    ['yard', 'Galangan', 'Masukkan nama galangan'],
                ].map(([name, label, placeholder]) => (
                    <label key={name}>
                        {label}
                        <Input
                            name={name}
                            required
                            type="text"
                            placeholder={placeholder}
                        />
                    </label>
                ))}
                <DateField
                    name="start"
                    label="Mulai pembangunan"
                    example="1 Juli 2020"
                />
                <DateField
                    name="end"
                    label="Selesai pembangunan"
                    example="20 Oktober 2020"
                />
                <label>
                    Struktur awal
                    <select name="source">
                        <option value="">Mulai dari struktur kosong</option>
                        {projects.map((p) => (
                            <option key={p.id} value={p.id}>
                                Salin struktur dari {p.name}
                            </option>
                        ))}
                    </select>
                </label>
                <Button className="justify-self-start">Buat proyek</Button>
            </Form>
        </section>
    );
}
function WorkDetail({
    groups,
    project,
    period,
    editing,
}: {
    groups: Group[];
    project: number;
    period: Period | null;
    editing: boolean;
}) {
    const realizationHintId = useId();
    const [gid, setGroup] = useState(
        new URLSearchParams(
            typeof window === 'undefined' ? '' : window.location.search,
        ).get('group') ?? '',
    );
    const [iid, setItem] = useState('');
    const [preview, setPreview] = useState<{
        item: string;
        progress: number | null;
    } | null>(null);
    const group = groups.find((g) => g.id === gid) ?? groups[0];
    const item = group?.items.find((i) => i.id === iid) ?? group?.items[0];
    const canEdit = editing && period !== null && !period.closed_at;
    if (!group || !item)
        return (
            <section>
                Tetapkan baseline di Form Builder untuk membuka pekerjaan.
            </section>
        );
    return (
        <>
            <section>
                <h2>Pilih pekerjaan</h2>
                <div className="flex flex-wrap gap-4">
                    <label>
                        Kelompok pekerjaan
                        <select
                            value={group.id}
                            onChange={(e) => {
                                setGroup(e.target.value);
                                setItem('');
                                setPreview(null);
                            }}
                        >
                            {groups.map((g) => (
                                <option key={g.id} value={g.id}>
                                    {g.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Item pekerjaan
                        <select
                            value={item.id}
                            onChange={(e) => {
                                setItem(e.target.value);
                                setPreview(null);
                            }}
                        >
                            {group.items.map((i) => (
                                <option key={i.id} value={i.id}>
                                    {i.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
            </section>
            <section>
                <small>{group.name}</small>
                <h2>{item.name}</h2>
                <p>Bobot dalam proyek {percent(item.weight)}</p>
                {canEdit && (
                    <p id={realizationHintId}>
                        Isi 0 jika hasil pengukuran nol. Biarkan kosong jika
                        tahap belum diukur.
                    </p>
                )}
                <form
                    key={item.id}
                    onInput={(event) => {
                        const values = new FormData(event.currentTarget);
                        let progress: number | null = null;
                        for (const stage of group.stages) {
                            const raw = values.get(stage.id);
                            if (raw !== null && raw !== '') {
                                progress =
                                    (progress ?? 0) +
                                    (Number(stage.weight) * Number(raw)) / 100;
                            }
                        }
                        setPreview({ item: item.id, progress });
                    }}
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (period) {
                            const entries = new FormData(e.currentTarget);
                            router.patch(
                                update.url([project, period.id]),
                                {
                                    item: item.id,
                                    values: Object.fromEntries(
                                        group.stages.map((s) => [
                                            s.id,
                                            String(entries.get(s.id) ?? ''),
                                        ]),
                                    ),
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setPreview(null),
                                },
                            );
                        }
                    }}
                >
                    <table>
                        <thead>
                            <tr>
                                <th>Tahap pengukuran</th>
                                <th>Bobot tahap</th>
                                <th>Realisasi (%)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {group.stages.map((s) => (
                                <tr key={s.id}>
                                    <td>{s.name}</td>
                                    <td>{percent(s.weight)}</td>
                                    <td>
                                        {canEdit ? (
                                            <Input
                                                className="ml-auto max-w-28"
                                                type="number"
                                                min="0"
                                                max="100"
                                                step="0.0001"
                                                aria-label={s.name}
                                                aria-describedby={realizationHintId}
                                                name={s.id}
                                                defaultValue={
                                                    item.values?.[s.id] ?? ''
                                                }
                                                placeholder="95"
                                            />
                                        ) : (
                                            percent(item.values?.[s.id])
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="mt-4 flex flex-wrap items-center gap-4">
                        {canEdit && <Button>Simpan progres</Button>}
                        {preview?.item === item.id ? (
                            <strong aria-live="polite">
                                Pratinjau progres {percent(preview.progress)}
                            </strong>
                        ) : (
                            <strong>
                                Progres item {percent(item.progress)}
                            </strong>
                        )}
                        <span>
                            Kontribusi {percent(item.contribution, ' pp')}
                        </span>
                    </div>
                </form>
            </section>
        </>
    );
}
export default function Dashboard(props: Monitoring) {
    const { project, projects, section, period, revision, history, report } =
        props;
    const { errors } = usePage().props;
    const create = section === 'baru' || !project;
    const title = create
        ? 'Buat proyek kapal'
        : sections[section as keyof typeof sections];
    return (
        <main className="monitoring min-h-full bg-background px-4 py-8 text-foreground md:px-8">
            <Head title={title} />
            <div className="mx-auto grid max-w-6xl gap-4">
                <header>
                    <small>MONITORING PEMBANGUNAN</small>
                    <h1 className="mt-1 text-3xl font-bold tracking-tight">
                        {title}
                    </h1>
                    {!create && (
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <p>
                                Nama Project: {project.name}
                                <br />
                                Kode: {project.code}
                                <br />
                                Galangan: {project.yard}
                            </p>
                            <Button asChild variant="outline">
                                <Link href={edit(project.id)}>Edit proyek</Link>
                            </Button>
                        </div>
                    )}
                    {project?.is_demo && !create && (
                        <span className="inline-block rounded-full bg-accent px-3 py-1 text-xs font-medium text-accent-foreground">
                            Data demo ASD Harbour Tug
                        </span>
                    )}
                </header>
                {section !== 'estimasi' && Object.keys(errors).length > 0 && (
                    <div
                        role="alert"
                        className="rounded border border-destructive/30 bg-destructive/10 p-3 text-destructive-foreground"
                    >
                        {Object.entries(errors).map(([k, v]) => (
                            <div key={k}>
                                {k}: {String(v)}
                            </div>
                        ))}
                    </div>
                )}
                {create ? (
                    <ProjectForm projects={projects} />
                ) : section === 'estimasi' ? (
                    <EstimateEditor
                        key={project.id}
                        project={project.id}
                        estimate={props.estimate}
                        groups={props.draft}
                        period={period?.id}
                    />
                ) : section === 'builder' ? (
                    <WbsEditor
                        key={project.id}
                        project={project.id}
                        draft={props.draft}
                        revision={revision}
                        hasEstimate={props.hasEstimate}
                    />
                ) : (
                    <>
                        {history.length > 0 && (
                            <div className="flex flex-wrap items-end gap-4">
                                <label>
                                    Periode laporan
                                    <select
                                        value={period?.id}
                                        onChange={(e) =>
                                            router.visit(
                                                workspace(section, {
                                                    query: {
                                                        project: project.id,
                                                        period: e.target.value,
                                                    },
                                                }),
                                            )
                                        }
                                    >
                                        {history.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.start} s.d. {p.end}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <p>
                                    Revisi {period?.revision} :{' '}
                                    {period?.closed_at ? 'Ditutup' : 'Terbuka'},{' '}
                                    {report.measured}/{report.expected} tahap
                                    diukur{period?.partial ? ', Parsial' : ''}
                                </p>
                                {period?.is_simulated && (
                                    <p>Realisasi tahap simulasi</p>
                                )}
                                {period?.reported_actual != null && (
                                    <p>Realisasi dari ringkasan proyek Excel</p>
                                )}
                            </div>
                        )}
                        {section === 'input' && (
                            <section>
                                <details open={!history.length}>
                                    <summary className="cursor-pointer font-semibold">
                                        Buat periode laporan
                                    </summary>
                                    {revision ? (
                                        <Form
                                            {...createPeriod.form(project.id)}
                                            className="mt-4 flex flex-wrap items-start gap-3"
                                        >
                                            <DateField
                                                name="start"
                                                label="Mulai"
                                                example="30 September 2020"
                                            />
                                            <DateField
                                                name="end"
                                                label="Sampai"
                                                example="6 Oktober 2020"
                                            />
                                            <label>
                                                Target kumulatif (%)
                                                <Input
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    max="100"
                                                    name="target"
                                                    placeholder={targetPlaceholder}
                                                />
                                            </label>
                                            <Button className="mt-6.5">Buat periode</Button>
                                        </Form>
                                    ) : (
                                        <p>
                                            Tetapkan baseline terlebih dahulu.
                                        </p>
                                    )}
                                </details>
                            </section>
                        )}
                        {section === 'ringkasan' ? (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    {[
                                        [
                                            'Realisasi fisik',
                                            percent(report.actual),
                                        ],
                                        [
                                            'Target rencana',
                                            percent(period?.target),
                                        ],
                                        [
                                            'Deviasi jadwal',
                                            percent(period?.deviation, ' pp'),
                                        ],
                                        [
                                            'Engineering Estimate (BAC)',
                                            props.bac == null
                                                ? 'Belum ada data'
                                                : money(props.bac),
                                        ],
                                    ].map(([label, value]) => (
                                        <section key={label}>
                                            <p>{label}</p>
                                            <strong className="text-xl">
                                                {value}
                                            </strong>
                                        </section>
                                    ))}
                                </div>
                                <History history={history} />
                                <section>
                                    <h2>Kelompok pekerjaan</h2>
                                    {report.groups.length ? (
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Kelompok</th>
                                                    <th>Bobot proyek</th>
                                                    <th>Realisasi kelompok</th>
                                                    <th>Kontribusi proyek</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {report.groups.map((g) => (
                                                    <tr key={g.id}>
                                                        <td>
                                                            <Link
                                                                className="font-medium text-primary"
                                                                href={workspace(
                                                                    'wbs',
                                                                    {
                                                                        query: {
                                                                            project:
                                                                                project.id,
                                                                            period: period?.id,
                                                                            group: g.id,
                                                                        },
                                                                    },
                                                                )}
                                                            >
                                                                {g.name}
                                                            </Link>
                                                        </td>
                                                        <td>
                                                            {percent(g.weight)}
                                                        </td>
                                                        <td>
                                                            {percent(
                                                                g.contribution !=
                                                                    null &&
                                                                    g.weight
                                                                    ? (100 *
                                                                          g.contribution) /
                                                                          g.weight
                                                                    : null,
                                                            )}
                                                        </td>
                                                        <td>
                                                            {percent(
                                                                g.contribution,
                                                                ' pp',
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    ) : (
                                        <Link
                                            href={workspace('builder', {
                                                query: { project: project.id },
                                            })}
                                        >
                                            Susun struktur pekerjaan
                                        </Link>
                                    )}
                                </section>
                            </>
                        ) : (
                            <WorkDetail
                                key={[
                                    project.id,
                                    period?.id,
                                    section,
                                    new URLSearchParams(
                                        typeof window === 'undefined'
                                            ? ''
                                            : window.location.search,
                                    ).get('group'),
                                ].join('-')}
                                groups={report.groups}
                                project={project.id}
                                period={period}
                                editing={section === 'input'}
                            />
                        )}
                        {section === 'input' && period && !period.closed_at && (
                            <section className="flex flex-wrap items-end justify-between gap-4">
                                <Form
                                    key={period.id}
                                    {...update.form([project.id, period.id])}
                                    className="flex items-end gap-2"
                                >
                                    <label>
                                        Target kumulatif (%)
                                        <Input
                                            name="target"
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.0001"
                                            defaultValue={period.target ?? ''}
                                            placeholder={targetPlaceholder}
                                        />
                                    </label>
                                    <Button variant="outline">
                                        Simpan target
                                    </Button>
                                </Form>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Tutup periode ini? Realisasi dan target akan dikunci.',
                                            )
                                        )
                                            router.patch(
                                                update.url([
                                                    project.id,
                                                    period.id,
                                                ]),
                                                { close: true },
                                            );
                                    }}
                                >
                                    Tutup periode
                                </Button>
                            </section>
                        )}
                    </>
                )}
            </div>
        </main>
    );
}
