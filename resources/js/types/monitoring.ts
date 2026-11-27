export type Weight = string | number | null;
export type WorkRow = { id: string; name: string; weight?: Weight };
export type Item = WorkRow & {
    weight_tons?: Weight;
    group_weight?: Weight;
    values?: Record<string, Weight>;
    progress?: number | null;
    contribution?: number | null;
};
export type Group = {
    id: string;
    name: string;
    items: Item[];
    stages: WorkRow[];
    type?: 'standard' | 'construction' | null;
    manual_project_weight?: Weight;
    project_weight?: Weight;
    weight?: number;
    progress?: number | null;
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
    label: string | null;
    axis_label: string;
    target: Weight;
    revision: number;
    closed_at: string | null;
    actual: number | null;
    reported_actual: Weight;
    is_simulated: boolean;
    partial: boolean;
    deviation: number | null;
    measurement_count: number;
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
    wbs_group_id: string | null;
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

export type SideView = { url: string; width: number; height: number };
export type Point = [number, number];
export type BlockAreas = Record<string, Point[]>;
export type MapBlock = { id: string; name: string; group: string };
export type BlockProgress = Record<
    string,
    { progress: number; period_label: string }
>;

export const blockStatuses = {
    unmeasured: {
        label: 'Belum diukur',
        description: 'Belum ada realisasi yang disimpan.',
        shape: 'fill-slate-400/45 stroke-slate-600',
        swatch: 'border-slate-600 bg-slate-400/60',
    },
    notStarted: {
        label: 'Belum mulai',
        description: 'Realisasi terukur 0%.',
        shape: 'fill-red-500/45 stroke-red-700',
        swatch: 'border-red-700 bg-red-500/60',
    },
    inProgress: {
        label: 'Dalam pengerjaan',
        description: 'Realisasi di atas 0% dan di bawah 100%.',
        shape: 'fill-amber-400/50 stroke-amber-600',
        swatch: 'border-amber-600 bg-amber-400/70',
    },
    done: {
        label: 'Selesai',
        description: 'Realisasi 100%.',
        shape: 'fill-green-500/45 stroke-green-700',
        swatch: 'border-green-700 bg-green-500/60',
    },
};

export function blockStatusOf(
    progress: number | null | undefined,
): keyof typeof blockStatuses {
    if (progress == null) {
        return 'unmeasured';
    }

    if (progress <= 0) {
        return 'notStarted';
    }

    return progress >= 100 ? 'done' : 'inProgress';
}

export type Monitoring = {
    sideView: SideView | null;
    blocks: MapBlock[];
    blockAreas: BlockAreas;
    blockStatus: BlockProgress;
    projects: Pick<Ship, 'name' | 'id'>[];
    project: Ship | null;
    draft: Group[];
    estimate: EstimateDraft | null;
    hasEstimate: boolean;
    estimateWeights: Record<string, number> | null;
    canImportFormBuilder: boolean;
    bac: string | null;
    revision: number;
    section: string;
    tab: 'realisasi' | 'periode';
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
    peta: 'Peta Blok',
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

export function percent(value: Weight | undefined, unit = '%', precision = 2) {
    return value == null
        ? 'Belum ada data'
        : Number(value).toLocaleString('id-ID', {
              minimumFractionDigits: 2,
              maximumFractionDigits: precision,
          }) + unit;
}
