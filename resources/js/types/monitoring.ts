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
export type EstimateLineInput = {
    id: number;
    description: string;
    quantity: string | null;
    unit: string | null;
    local_price_idr: string | null;
    local_price_is_lump_sum: boolean;
    usd_unit_price: string | null;
    wbs_item_id: string | null;
};
export type EstimateLine = EstimateLineInput & {
    position: number;
    local_idr: string | null;
    import_usd: string | null;
    equivalent_idr: string | null;
};
export type EstimateInput = {
    exchange_rate_idr_per_usd: string | null;
    exchange_rate_date: string | null;
    exchange_rate_is_assumption: boolean;
    lines: EstimateLineInput[];
};
export type EstimateDraft = Omit<EstimateInput, 'lines'> & {
    lines: EstimateLine[];
    equivalent_idr: string | null;
    local_idr: string | null;
    import_usd: string | null;
};

export type Monitoring = {
    projects: Pick<Ship, 'name' | 'id'>[];
    project: Ship | null;
    draft: Group[];
    estimate: EstimateDraft | null;
    hasEstimate: boolean;
    canImportFormBuilder: boolean;
    bac: string | null;
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
    estimasi: 'Engineering Estimate',
    wbs: 'Struktur Pekerjaan dan Pengukuran Progres',
    builder: 'Form Builder',
    input: 'Input Progres',
    baru: 'Buat proyek',
};
export function money(
    value: string | null | undefined,
    currency: 'IDR' | 'USD' = 'IDR',
) {
    if (value == null) {
        return '—';
    }

    const [whole, fraction = '00'] = value.split('.');
    const formatted = new Intl.NumberFormat('id-ID').format(BigInt(whole));
    return (currency === 'IDR' ? 'Rp' : 'US$') + formatted + ',' + fraction;
}

export function percent(value: Weight | undefined, unit = '%') {
    return value == null
        ? 'Belum ada data'
        : Number(value).toLocaleString('id-ID', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          }) + unit;
}
