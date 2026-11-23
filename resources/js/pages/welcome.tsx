import { Head, Link, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import GalanganBrand from '@/components/galangan-brand';
import { dashboard, login, register } from '@/routes';
import '../../css/galangan-public.css';
const months = ['Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const projects = [
    {
        name: 'Feri 1.500 GT',
        code: 'GLG-001',
        phase: 'Outfitting',
        plan: [4, 10, 21, 38, 55, 72, 91, 100],
        actual: [3, 9, 18, 35, 51, 68],
    },
    {
        name: 'Kapal Tunda 28 m',
        code: 'GLG-002',
        phase: 'Integrasi sistem',
        plan: [6, 15, 29, 49, 68, 83, 95, 100],
        actual: [6, 17, 31, 51, 71, 86],
    },
    {
        name: 'Tongkang 270 kaki',
        code: 'GLG-003',
        phase: 'Perakitan blok',
        plan: [2, 6, 14, 23, 38, 52, 79, 100],
        actual: [2, 5, 12, 22, 34, 49],
    },
];

type Project = (typeof projects)[number];
const latest = (project: Project) => project.actual[project.actual.length - 1];
const planned = (project: Project) => project.plan[project.actual.length - 1];
const delta = (project: Project) => latest(project) - planned(project);
const onTrack = projects.filter((project) => delta(project) >= 0).length;
const averageProgress = (
    projects.reduce((sum, project) => sum + latest(project), 0) /
    projects.length
).toLocaleString('id-ID', { maximumFractionDigits: 1 });

function ProgressPlot({ project }: { project: Project }) {
    const x = (index: number) => (index * 100) / (months.length - 1);
    const points = (values: number[]) =>
        values.map((value, index) => x(index) + ',' + (100 - value)).join(' ');
    return (
        <div
            className="chart-plot"
            role="img"
            aria-label={
                'Kurva S ' +
                project.name +
                '. Data bulanan tersedia di bawah grafik.'
            }
        >
            {[0, 25, 50, 75, 100].map((level) => (
                <div
                    key={level}
                    className="chart-grid"
                    style={{ bottom: level + '%' }}
                >
                    <span>{level}%</span>
                </div>
            ))}
            {months.map((month, index) => (
                <span
                    className="chart-month"
                    key={month}
                    style={{ left: x(index) + '%' }}
                >
                    {month}
                </span>
            ))}
            <svg
                viewBox="0 0 100 100"
                preserveAspectRatio="none"
                aria-hidden="true"
            >
                <defs>
                    <linearGradient id="actual-fill" x2="0" y2="1">
                        <stop stopColor="#008577" stopOpacity=".2" />
                        <stop
                            offset="1"
                            stopColor="#008577"
                            stopOpacity=".02"
                        />
                    </linearGradient>
                </defs>
                <polygon
                    points={
                        '0,100 ' +
                        points(project.actual) +
                        ' ' +
                        x(project.actual.length - 1) +
                        ',100'
                    }
                    fill="url(#actual-fill)"
                />
                <polyline
                    points={points(project.plan)}
                    fill="none"
                    stroke="#657f96"
                    strokeWidth="2.5"
                    strokeDasharray="6 5"
                    vectorEffect="non-scaling-stroke"
                />
                <polyline
                    points={points(project.actual)}
                    fill="none"
                    stroke="#008577"
                    strokeWidth="2.5"
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
            {project.actual.map((value, index) => (
                <span
                    key={months[index]}
                    title={months[index] + ': ' + value + '%'}
                    className="absolute size-1.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#008577]"
                    style={{ left: x(index) + '%', top: 100 - value + '%' }}
                />
            ))}
        </div>
    );
}
export default function Welcome() {
    const { auth } = usePage().props;
    const [projectCode, setProjectCode] = useState(projects[0].code);
    const selectRef = useRef<HTMLSelectElement>(null);
    const selected =
        projects.find((project) => project.code === projectCode) ?? projects[0];
    const difference = delta(selected);
    return (
        <div className="galangan-public min-h-screen">
            <Head title="Ringkasan proyek" />
            <a className="skip" href="#main">
                Lewati ke konten
            </a>
            <header className="site-header">
                <div className="shell topbar">
                    <GalanganBrand />
                    <nav aria-label="Navigasi utama">
                        <a className="current" href="#main" aria-current="page">
                            Ringkasan
                        </a>
                        <a href="#proyek">Daftar proyek</a>
                    </nav>
                    <div className="flex items-center gap-4">
                        {!auth.user && (
                            <Link className="back-link" href={register()}>
                                Daftar
                            </Link>
                        )}
                        <Link
                            className="button button-primary nav-login"
                            href={auth.user ? dashboard() : login()}
                        >
                            {auth.user ? 'Buka ringkasan' : 'Masuk'}
                        </Link>
                    </div>
                </div>
            </header>
            <main className="shell" id="main">
                <section className="hero" aria-labelledby="hero-title">
                    <div className="hero-copy">
                        <span className="eyebrow">
                            <i></i> MONITORING GALANGAN
                        </span>
                        <h1 id="hero-title">
                            Progres pembangunan
                            <br className="wide" /> kapal dalam satu layar.
                        </h1>
                        <p>
                            Lihat perkembangan proyek, bandingkan rencana dengan
                            realisasi, dan pantau jadwal pembangunan kapal.
                        </p>
                        <a className="button button-light" href="#proyek">
                            Lihat daftar proyek
                        </a>
                    </div>
                    <span className="hero-caption">
                        Ilustrasi pembangunan kapal di galangan
                    </span>
                </section>
                <section className="overview" aria-labelledby="overview-title">
                    <div className="section-head">
                        <div>
                            <h2 id="overview-title">Ringkasan proyek</h2>
                            <p>Periode pelaporan hingga 28 Oktober 2026</p>
                        </div>
                        <span className="demo-label">
                            <i></i> Data simulasi
                        </span>
                    </div>
                    <div className="metrics">
                        <article className="metric">
                            <span className="metric-icon" aria-hidden="true">
                                ▤
                            </span>
                            <p>Proyek aktif</p>
                            <strong id="active-count">{projects.length}</strong>
                            <small>Sedang dalam pengerjaan</small>
                        </article>
                        <article className="metric">
                            <span className="metric-icon" aria-hidden="true">
                                ◔
                            </span>
                            <p>Rata-rata progres aktual</p>
                            <strong id="average-progress">
                                {averageProgress}%
                            </strong>
                            <small>Seluruh proyek aktif</small>
                        </article>
                        <article className="metric">
                            <span
                                className="metric-icon mint"
                                aria-hidden="true"
                            >
                                ✓
                            </span>
                            <p>Sesuai atau lebih cepat</p>
                            <strong id="on-track-count">{onTrack}</strong>
                            <small>Aktual mencapai target periode</small>
                        </article>
                        <article className="metric">
                            <span
                                className="metric-icon amber"
                                aria-hidden="true"
                            >
                                !
                            </span>
                            <p>Di bawah target</p>
                            <strong id="late-count">
                                {projects.length - onTrack}
                            </strong>
                            <small>Aktual tertinggal dari rencana</small>
                        </article>
                    </div>
                </section>
                <section
                    className="panel chart-panel"
                    id="kurva"
                    aria-labelledby="chart-title"
                >
                    <div className="section-head">
                        <div>
                            <h2 id="chart-title">Kurva S pembangunan</h2>
                            <p>
                                Progres kumulatif rencana dan aktual per bulan
                            </p>
                        </div>
                        <div className="select-wrap">
                            <label className="sr" htmlFor="project-select">
                                Pilih proyek
                            </label>
                            <select
                                id="project-select"
                                ref={selectRef}
                                value={selected.code}
                                onChange={(event) =>
                                    setProjectCode(event.target.value)
                                }
                            >
                                {projects.map((project) => (
                                    <option
                                        key={project.code}
                                        value={project.code}
                                    >
                                        {project.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    <div className="chart-meta">
                        <div className="legend">
                            <span>
                                <i className="plan-key"></i>Rencana
                            </span>
                            <span>
                                <i className="actual-key"></i>Aktual
                            </span>
                        </div>
                        <p
                            className="variance"
                            id="variance"
                            aria-live="polite"
                            data-status={difference < 0 ? 'late' : 'ok'}
                        >
                            Selisih Oktober {difference > 0 ? '+' : ''}
                            {difference} poin persentase
                        </p>
                    </div>
                    <ProgressPlot project={selected} />
                    <details className="chart-data">
                        <summary>Lihat data grafik</summary>
                        <div className="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Bulan</th>
                                        <th>Rencana</th>
                                        <th>Aktual</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {months.map((month, index) => (
                                        <tr key={month}>
                                            <td>{month}</td>
                                            <td>{selected.plan[index]}%</td>
                                            <td>
                                                {selected.actual[index] ===
                                                undefined
                                                    ? 'Belum tercatat'
                                                    : selected.actual[index] +
                                                      '%'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </details>
                </section>
                <section
                    className="panel projects"
                    id="proyek"
                    aria-labelledby="project-title"
                >
                    <div className="section-head">
                        <div>
                            <h2 id="project-title">Daftar proyek</h2>
                            <p>Pilih kapal untuk melihat kurva progresnya.</p>
                        </div>
                    </div>
                    <div className="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kapal</th>
                                    <th>Fase saat ini</th>
                                    <th>Aktual / Rencana</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {projects.map((project) => (
                                    <tr key={project.code}>
                                        <td>
                                            <button
                                                onClick={() => {
                                                    setProjectCode(
                                                        project.code,
                                                    );
                                                    selectRef.current?.focus({
                                                        preventScroll: true,
                                                    });
                                                    selectRef.current
                                                        ?.closest('section')
                                                        ?.scrollIntoView();
                                                }}
                                            >
                                                {project.name}
                                            </button>
                                        </td>
                                        <td>{project.phase}</td>
                                        <td>
                                            {latest(project)}% /{' '}
                                            {planned(project)}%
                                        </td>
                                        <td>
                                            <span
                                                className={
                                                    delta(project) < 0
                                                        ? 'status late'
                                                        : 'status'
                                                }
                                            >
                                                {delta(project) < 0
                                                    ? 'Di bawah target'
                                                    : 'Mencapai target'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>
            <footer className="site-footer">
                <div className="shell footer-inner">
                    <span>Galangan Monitor</span>
                    <span>Monitoring pembangunan kapal</span>
                </div>
            </footer>
        </div>
    );
}
