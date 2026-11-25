import { router, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    applyFormBuilderImport,
    previewFormBuilderImport,
} from '@/actions/App/Http/Controllers/MonitoringController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/types/monitoring';
import type { Group } from '@/types/monitoring';

type ImportPreview = {
    preview_token: string;
    groups: { name: string; item_count: number }[];
    item_count: number;
    exchange_rate_idr_per_usd: string | null;
    exchange_rate_date: string | null;
    exchange_rate_is_assumption: boolean;
    local_idr: string;
    import_usd: string;
    equivalent_idr: string;
};

export function FormBuilderImport({
    project,
    disabled,
    onImported,
}: {
    project: number;
    disabled: boolean;
    onImported: (groups: Group[]) => void;
}) {
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<ImportPreview | null>(null);
    const [errors, setErrors] = useState<string[]>([]);
    const [applying, setApplying] = useState(false);
    const upload = useHttp<{ file: File | null }, ImportPreview>({
        file: null,
    });
    const processing = applying || upload.processing;

    function changeOpen(nextOpen: boolean) {
        if (processing) {
            return;
        }

        setOpen(nextOpen);
        if (!nextOpen) {
            clearImport();
        }
    }

    function clearImport() {
        upload.reset();
        setPreview(null);
        setErrors([]);
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        const file = upload.data.file;
        if (!file || processing) {
            return;
        }

        setErrors([]);
        if (preview) {
            router.post(
                applyFormBuilderImport.url(project),
                { file, preview_token: preview.preview_token },
                {
                    preserveScroll: true,
                    errorBag: 'formBuilderImport',
                    onStart: () => setApplying(true),
                    onSuccess: (page) => {
                        onImported(page.props.draft as Group[]);
                        setOpen(false);
                        clearImport();
                    },
                    onError: (validationErrors) => {
                        setErrors(Object.values(validationErrors).flat());
                        setPreview(null);
                    },
                    onFinish: () => setApplying(false),
                },
            );
            return;
        }

        void upload
            .post(previewFormBuilderImport.url(project), {
                onSuccess: setPreview,
                onError: (validationErrors) =>
                    setErrors(Object.values(validationErrors).flat()),
                onHttpException: () => {
                    setErrors(['Pratinjau gagal dimuat. Coba lagi.']);
                    return false;
                },
                onNetworkError: () => {
                    setErrors(['Koneksi terputus. Coba unggah kembali.']);
                    return false;
                },
            })
            .catch(() => undefined);
    }

    return (
        <Dialog open={open} onOpenChange={changeOpen}>
            <DialogTrigger asChild>
                <Button type="button" disabled={disabled}>
                    Impor Excel
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Impor Excel</DialogTitle>
                    <DialogDescription>
                        Tinjau struktur pekerjaan dan Engineering Estimate
                        sebelum menyimpannya sebagai draft proyek.
                    </DialogDescription>
                </DialogHeader>
                <form className="grid min-w-0 gap-4" onSubmit={submit}>
                    <div className="grid gap-2">
                        <Label htmlFor="form-builder-workbook">
                            File template yang sudah diisi
                        </Label>
                        <Input
                            id="form-builder-workbook"
                            type="file"
                            name="file"
                            accept=".xlsx"
                            disabled={processing}
                            onChange={(event) => {
                                upload.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                );
                                setPreview(null);
                                setErrors([]);
                            }}
                        />
                        <p className="text-sm text-muted-foreground">
                            Format .xlsx, maksimal 5 MB.
                        </p>
                    </div>

                    {errors.length > 0 && (
                        <ul
                            role="alert"
                            className="max-h-40 list-disc overflow-y-auto pl-5 text-sm text-destructive"
                        >
                            {errors.map((error) => (
                                <li key={error}>{error}</li>
                            ))}
                        </ul>
                    )}

                    {preview && <PreviewSummary preview={preview} />}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={processing}
                            onClick={() => changeOpen(false)}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            disabled={!upload.data.file || processing}
                        >
                            {preview
                                ? applying
                                    ? 'Menyimpan…'
                                    : 'Terapkan impor'
                                : upload.processing
                                  ? 'Meninjau…'
                                  : 'Tinjau file'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function PreviewSummary({ preview }: { preview: ImportPreview }) {
    const rate = preview.exchange_rate_idr_per_usd;
    const rateDisplay = rate
        ? new Intl.NumberFormat('id-ID', { maximumFractionDigits: 6 }).format(
              Number(rate),
          )
        : null;
    const totals = [
        ['Biaya lokal', money(preview.local_idr)],
        ['Biaya impor', money(preview.import_usd, 'USD')],
        ['Total EE', money(preview.equivalent_idr)],
    ];

    return (
        <section className="grid gap-3 rounded-md border p-3 text-sm">
            <h3 className="font-medium">
                {preview.groups.length} kelompok · {preview.item_count} item
            </h3>
            <ul className="max-h-32 divide-y overflow-y-auto">
                {preview.groups.map((group) => (
                    <li
                        key={group.name}
                        className="flex justify-between gap-3 py-1"
                    >
                        <span className="min-w-0 wrap-break-word">
                            {group.name}
                        </span>
                        <span className="shrink-0 text-muted-foreground">
                            {group.item_count} item
                        </span>
                    </li>
                ))}
            </ul>
            <p>
                Kurs{' '}
                {rateDisplay ? 'Rp' + rateDisplay + ' per USD' : 'belum diisi'}
                {rateDisplay && preview.exchange_rate_is_assumption
                    ? ' (asumsi)'
                    : ''}
                {preview.exchange_rate_date
                    ? ' · ' + preview.exchange_rate_date
                    : ''}
            </p>
            <dl className="grid gap-1 sm:grid-cols-3">
                {totals.map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-muted-foreground">{label}</dt>
                        <dd className="font-medium wrap-break-word">{value}</dd>
                    </div>
                ))}
            </dl>
            <p className="text-muted-foreground">
                Isi tahap pengukuran di Form Builder sebelum menetapkan
                baseline.
            </p>
        </section>
    );
}
