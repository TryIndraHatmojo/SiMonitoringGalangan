import { Form, Link, router } from '@inertiajs/react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { workspace } from '@/routes';
import { period as createPeriod, update } from '@/routes/projects';
import { destroy } from '@/routes/projects/periods';
import { percent, type Period } from '@/types/monitoring';

const targetPlaceholder = '98.5';

export function DateField({
    name,
    label,
    example,
    defaultValue,
    error,
}: {
    name: 'start' | 'end';
    label: string;
    example?: string;
    defaultValue?: string;
    error?: string;
}) {
    const id = useId();
    const hintId = id + '-hint';
    const errorId = id + '-error';

    return (
        <div className="grid gap-1">
            <label>
                {label}
                <Input
                    name={name}
                    required
                    type="date"
                    defaultValue={defaultValue}
                    aria-invalid={!!error}
                    aria-describedby={error ? hintId + ' ' + errorId : hintId}
                />
            </label>
            <span id={hintId} className="text-xs text-muted-foreground">
                {example && `Mis. ${example}`}
            </span>
            <InputError id={errorId} message={error} />
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
                Label ini tampil pada sumbu X S Curve. Jika dikosongkan, grafik
                menampilkan tanggal akhir periode.
            </span>
            <InputError id={errorId} message={error} />
        </div>
    );
}

export function reopenPeriod(project: number, period: Period): void {
    if (
        window.confirm(
            `Buka kembali periode ${period.start} s.d. ${period.end}? Realisasi, tanggal, target, dan label dapat diubah lagi.`,
        )
    ) {
        router.patch(
            update.url([project, period.id]),
            { reopen: true },
            { preserveScroll: true },
        );
    }
}

function deletePeriod(project: number, period: Period): void {
    const impact = period.measurement_count
        ? ` ${period.measurement_count} data realisasi tahap akan ikut terhapus.`
        : '';

    if (
        window.confirm(
            `Hapus periode ${period.start} s.d. ${period.end}?${impact}`,
        )
    ) {
        router.delete(destroy.url([project, period.id]));
    }
}

export function PeriodTabs({
    project,
    period,
    active,
}: {
    project: number;
    period?: number;
    active: 'realisasi' | 'periode';
}) {
    return (
        <nav aria-label="Menu input progres" className="flex gap-4 border-b">
            {(
                [
                    ['realisasi', 'Isi Realisasi'],
                    ['periode', 'Kelola Periode'],
                ] as const
            ).map(([tab, label]) => (
                <Link
                    key={tab}
                    href={workspace('input', {
                        query: { project, period, tab },
                    })}
                    preserveScroll
                    aria-current={tab === active ? 'page' : undefined}
                    className={`-mb-px border-b-2 px-1 py-2 text-sm font-medium ${
                        tab === active
                            ? 'border-primary text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    }`}
                >
                    {label}
                </Link>
            ))}
        </nav>
    );
}

function CreatePeriodDialog({
    project,
    disabled,
}: {
    project: number;
    disabled: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" disabled={disabled}>
                    Buat periode
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Buat periode laporan</DialogTitle>
                    <DialogDescription>
                        Tentukan rentang tanggal yang belum dipakai periode
                        lain.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...createPeriod.form(project)}
                    errorBag="createPeriod"
                    onSuccess={() => setOpen(false)}
                    className="monitoring grid items-start gap-3 sm:grid-cols-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <DateField
                                name="start"
                                label="Mulai"
                                example="30 September 2020"
                                error={errors.start}
                            />
                            <DateField
                                name="end"
                                label="Sampai"
                                example="6 Oktober 2020"
                                error={errors.end}
                            />
                            <div className="grid gap-1">
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
                                <InputError message={errors.target} />
                            </div>
                            <PeriodLabelField error={errors.label} />
                            <Button
                                className="justify-self-start"
                                disabled={processing}
                            >
                                Simpan periode baru
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function EditPeriodForm({
    project,
    period,
    onClose,
}: {
    project: number;
    period: Period;
    onClose: () => void;
}) {
    return (
        <Form
            {...update.form([project, period.id])}
            errorBag="updatePeriod"
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="grid items-start gap-3 text-left sm:grid-cols-2 lg:grid-cols-4"
        >
            {({ errors, processing }) => (
                <>
                    <DateField
                        name="start"
                        label="Mulai"
                        defaultValue={period.start}
                        error={errors.start}
                    />
                    <DateField
                        name="end"
                        label="Sampai"
                        defaultValue={period.end}
                        error={errors.end}
                    />
                    <div className="grid gap-1">
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
                        <InputError message={errors.target} />
                    </div>
                    <PeriodLabelField
                        value={period.label}
                        error={errors.label}
                    />
                    <div className="flex gap-2">
                        <Button disabled={processing}>Simpan periode</Button>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Batal
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export function ReportPeriodList({
    project,
    periods,
    selected,
    canCreate,
}: {
    project: number;
    periods: Period[];
    selected?: number;
    canCreate: boolean;
}) {
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <section>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2>Daftar periode laporan</h2>
                <CreatePeriodDialog project={project} disabled={!canCreate} />
            </div>
            {!canCreate && <p>Tetapkan baseline terlebih dahulu.</p>}
            {periods.length === 0 ? (
                <p>Belum ada periode laporan.</p>
            ) : (
                <div className="overflow-x-auto">
                    <table>
                        <thead>
                            <tr>
                                <th>Periode</th>
                                <th>Label</th>
                                <th>Target</th>
                                <th>Status</th>
                                <th>Realisasi terisi</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            {periods.map((period) =>
                                editing === period.id && !period.closed_at ? (
                                    <tr key={period.id}>
                                        <td colSpan={6}>
                                            <EditPeriodForm
                                                project={project}
                                                period={period}
                                                onClose={() => setEditing(null)}
                                            />
                                        </td>
                                    </tr>
                                ) : (
                                    <tr
                                        key={period.id}
                                        className={
                                            period.id === selected
                                                ? 'bg-accent/50'
                                                : undefined
                                        }
                                        aria-current={
                                            period.id === selected
                                                ? 'true'
                                                : undefined
                                        }
                                    >
                                        <td>
                                            {period.start} s.d. {period.end}
                                        </td>
                                        <td>{period.label ?? '-'}</td>
                                        <td>{percent(period.target)}</td>
                                        <td>
                                            {period.closed_at
                                                ? 'Ditutup'
                                                : 'Terbuka'}
                                        </td>
                                        <td>{period.measurement_count}</td>
                                        <td>
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                >
                                                    <Link
                                                        href={workspace(
                                                            'input',
                                                            {
                                                                query: {
                                                                    project,
                                                                    period: period.id,
                                                                },
                                                            },
                                                        )}
                                                    >
                                                        Isi realisasi
                                                    </Link>
                                                </Button>
                                                {period.closed_at ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            reopenPeriod(
                                                                project,
                                                                period,
                                                            )
                                                        }
                                                    >
                                                        Buka kembali
                                                    </Button>
                                                ) : (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setEditing(
                                                                    period.id,
                                                                )
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="destructive"
                                                            onClick={() =>
                                                                deletePeriod(
                                                                    project,
                                                                    period,
                                                                )
                                                            }
                                                        >
                                                            Hapus
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ),
                            )}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
