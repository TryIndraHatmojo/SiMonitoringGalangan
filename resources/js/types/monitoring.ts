export type EstimateLine = {
    source_group?: string;
    code: string;
    name: string;
    unit: string;
    quantity: string;
    local: string;
    usd: string;
    item: string;
};
export type Estimate = {
    rate: string;
    date: string;
    source: string;
    assumption: boolean;
    lines: EstimateLine[];
    bac?: string;
};
export const rupiah = (value: number | string | null | undefined) =>
    value == null
        ? 'Belum tersedia'
        : Number(value).toLocaleString('id-ID', {
              style: 'currency',
              currency: 'IDR',
              maximumFractionDigits: 2,
          });
export type Weight = string | number | null;
export type WorkRow = { id: string; name: string; weight: Weight };
export type Item = WorkRow & {
    stages?: WorkRow[];
    template_name?: string;
    values?: Record<string, Weight>;
    progress?: number | null;
    contribution?: number | null;
};
export type Group = {
    id: string;
    name: string;
    items: Item[];
    stages: WorkRow[];
    weight?: number;
    contribution?: number | null;
};
export type Ship = {
    id: number;
    name: string;
    code: string;
    yard: string;
    is_demo: boolean;
    estimate: Estimate | null;
    start: string;
    end: string;
};
export type Period = {
    id: number;
    start: string;
    end: string;
    target: Weight;
    revision: number;
    closed_at: string | null;
    scheduled: boolean;
    metrics: Record<string, number | null>;
    actual_cost: string | null;
    cost_source: string | null;
    cost_simulated: boolean;
    verified_at: string | null;
    actual: number | null;
    reported_actual: Weight;
    is_simulated: boolean;
    partial: boolean;
    deviation: number | null;
};
export type Monitoring = {
    activeEstimate: Estimate | null;
    comparison: Period | null;
    comparisonReport: { groups: Group[] } | null;
    importPreview: {
        token: string;
        summary: { bac: string | null; warnings: string[] };
        counts: Record<string, number>;
    } | null;
    importDone: boolean | null;
    projects: Pick<Ship, 'name' | 'id'>[];
    project: Ship | null;
    draft: Group[];
    revision: number;
    section: string;
    period: Period | null;
    history: Period[];
    report: {
        groups: Group[];
        actual: number | null;
        measured: number;
        expected: number;
    };
};
export const sections = {
    ringkasan: 'Ringkasan',
    wbs: 'Struktur Pekerjaan dan Pengukuran Progres',
    builder: 'Form Builder',
    estimasi: 'Estimasi biaya',
    input: 'Input Laporan',
    baru: 'Buat proyek',
};
export function percent(value: Weight | undefined, unit = '%') {
    return value == null
        ? 'Belum ada data'
        : Number(value).toLocaleString('id-ID', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          }) + unit;
}
