export type Weight = string | number | null;
export type WorkRow = { id: string; name: string; weight: Weight };
export type Item = WorkRow & {
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
    actual: number | null;
    reported_actual: Weight;
    is_simulated: boolean;
    partial: boolean;
    deviation: number | null;
};
export type Monitoring = {
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
    wbs: 'Struktur WBS',
    builder: 'Form Builder',
    input: 'Input Progres',
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
