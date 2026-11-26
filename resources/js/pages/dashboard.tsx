import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useId, useState } from 'react';
import { EstimateEditor } from '@/components/estimate-editor';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
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

function PeriodLabelField({
    value,
    error,
}: {
    value?: string | null;
    error?: string;
}) {
    const id = useId();
    const hintId = id + '-hint';
    const errorId = id + '-error';

    return (
        <div className="grid w-full max-w-sm gap-1">
            <label htmlFor={id}>Label periode (opsional)</label>
            <Input
                id={id}
                name="label"
                type="text"
                defaultValue={value ?? ''}
                maxLength={40}
                placeholder="Mis. Minggu 14"
                aria-invalid={!!error}
                aria-describedby={error ? hintId + ' ' + errorId : hintId}
            />
            <span id={hintId} className="text-xs text-muted-foreground">
                Label ini tampil pada sumbu X Kurva S. Jika dikosongkan, grafik
                menampilkan tanggal akhir periode.
            </span>
            <InputError id={errorId} message={error} />
        </div>
    );
}

function ProjectForm({ projects }: Pick<Monitoring, 'projects'>) {
    const [source, setSource] = useState('');

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
                <div className="grid min-w-0 gap-1.5">
                    <label htmlFor="project-source">Struktur awal</label>
                    <Combobox
                        id="project-source"
                        label="Struktur awal"
                        name="source"
                        value={source}
                        onValueChange={setSource}
                        options={[
                            { value: '', label: 'Mulai dari struktur kosong' },
                            ...projects.map((project) => ({
                                value: String(project.id),
                                label: `Salin struktur dari ${project.name}`,
                            })),
                        ]}
                    />
                </div>
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
    const construction = group?.type === 'construction';
    const itemLabel = construction ? 'Nama blok' : 'Item pekerjaan';
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
                <div className="grid items-start gap-4 sm:grid-cols-2">
                    <div className="grid min-w-0 gap-1.5">
                        <label htmlFor="work-group">Kelompok pekerjaan</label>
                        <Combobox
                            id="work-group"
                            label="Kelompok pekerjaan"
                            value={group.id}
                            onValueChange={(value) => {
                                setGroup(value);
                                setItem('');
                                setPreview(null);
                            }}
                            options={groups.map((group) => ({
                                value: group.id,
                                label: group.name,
                            }))}
                        />
                    </div>
                    <div className="grid min-w-0 gap-1.5">
                        <label htmlFor="work-item">{itemLabel}</label>
                        <Combobox
                            id="work-item"
                            label={itemLabel}
                            value={item.id}
                            onValueChange={(value) => {
                                setItem(value);
                                setPreview(null);
                            }}
                            options={group.items.map((item) => ({
                                value: item.id,
                                label: item.name,
                            }))}
                        />
                    </div>
                </div>
            </section>
            <section>
                <small>{group.name}</small>
                <h2>{item.name}</h2>
                <p>Bobot dalam kelompok {percent(item.group_weight, '%', 6)}</p>
                <p>Bobot dalam proyek {percent(item.weight, '%', 6)}</p>
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
                            if (typeof raw === 'string' && raw !== '') {
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
                                        group.stages.map((stage) => {
                                            const value = entries.get(stage.id);
                                            return [
                                                stage.id,
                                                typeof value === 'string'
                                                    ? value
                                                    : '',
                                            ];
                                        }),
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
                                {construction ? 'Progres blok' : 'Progres item'}{' '}
                                {percent(item.progress)}
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
                        estimateWeights={props.estimateWeights}
                        canImportFormBuilder={props.canImportFormBuilder}
                    />
                ) : (
                    <>
                        {history.length > 0 && (
                            <div className="flex flex-wrap items-end gap-4">
                                <div className="grid w-full min-w-0 gap-1.5 sm:w-56">
                                    <label htmlFor="report-period">
                                        Periode laporan
                                    </label>
                                    <Combobox
                                        id="report-period"
                                        label="Periode laporan"
                                        value={String(
                                            period?.id ?? history[0]?.id ?? '',
                                        )}
                                        onValueChange={(value) =>
                                            router.visit(
                                                workspace(section, {
                                                    query: {
                                                        project: project.id,
                                                        period: value,
                                                    },
                                                }),
                                            )
                                        }
                                        options={history.map(
                                            ({ id, start, end }) => ({
                                                value: String(id),
                                                label: `${start} s.d. ${end}`,
                                            }),
                                        )}
                                    />
                                </div>
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
                                    <p>Realisasi dari ringkasan proyek</p>
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
                                            errorBag="createPeriod"
                                            className="mt-4 grid items-start gap-3 sm:grid-cols-2 lg:grid-cols-4"
                                        >
                                            {({ errors, processing }) => (
                                                <>
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
                                                            placeholder={
                                                                targetPlaceholder
                                                            }
                                                        />
                                                    </label>
                                                    <PeriodLabelField
                                                        error={errors.label}
                                                    />
                                                    <Button
                                                        className="justify-self-start"
                                                        disabled={processing}
                                                    >
                                                        Buat periode
                                                    </Button>
                                                </>
                                            )}
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
                                                            {percent(
                                                                g.weight,
                                                                '%',
                                                                6,
                                                            )}
                                                        </td>
                                                        <td>
                                                            {percent(
                                                                g.progress,
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
                            <section className="flex flex-wrap items-start justify-between gap-4">
                                <Form
                                    key={period.id}
                                    {...update.form([project.id, period.id])}
                                    errorBag="updatePeriod"
                                    className="grid items-start gap-3 sm:grid-cols-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <label>
                                                Target kumulatif (%)
                                                <Input
                                                    name="target"
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    step="0.0001"
                                                    defaultValue={
                                                        period.target ?? ''
                                                    }
                                                    placeholder={
                                                        targetPlaceholder
                                                    }
                                                />
                                            </label>
                                            <PeriodLabelField
                                                value={period.label}
                                                error={errors.label}
                                            />
                                            <Button
                                                className="justify-self-start"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Simpan periode
                                            </Button>
                                        </>
                                    )}
                                </Form>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        if (
                                            window.confirm(
                                                'Tutup periode ini? Realisasi, target, dan label periode akan dikunci.',
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
