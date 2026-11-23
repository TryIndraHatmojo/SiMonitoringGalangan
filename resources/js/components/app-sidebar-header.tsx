import { Link, router, usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { workspace } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import type { Monitoring } from '@/types/monitoring';
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItem[];
}) {
    const { project, projects, section } = usePage<Monitoring>().props;
    return (
        <header className="flex min-h-16 items-center gap-4 border-b bg-card px-4 text-card-foreground">
            <SidebarTrigger />
            {projects ? (
                <>
                    <label className="flex items-center gap-2 text-sm">
                        <span className="hidden sm:inline">Proyek kapal</span>
                        <select
                            aria-label="Proyek kapal"
                            className="max-w-52 rounded border bg-background p-2"
                            value={project?.id ?? ''}
                            onChange={(e) =>
                                router.visit(
                                    workspace(
                                        section === 'baru'
                                            ? 'ringkasan'
                                            : section,
                                        { query: { project: e.target.value } },
                                    ),
                                )
                            }
                        >
                            {!projects.length && (
                                <option>Belum ada proyek</option>
                            )}
                            {projects.map((p) => (
                                <option value={p.id} key={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Link
                        className="ml-auto text-sm font-medium text-primary"
                        href={workspace('baru')}
                    >
                        + Buat proyek
                    </Link>
                </>
            ) : (
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            )}
        </header>
    );
}
