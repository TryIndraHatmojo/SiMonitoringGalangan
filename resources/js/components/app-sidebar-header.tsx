import { Link, router, usePage } from '@inertiajs/react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Combobox } from '@/components/ui/combobox';
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
                    <div className="flex min-w-0 items-center gap-2 text-sm">
                        <label
                            htmlFor="active-project"
                            className="hidden shrink-0 sm:inline"
                        >
                            Proyek kapal
                        </label>
                        <Combobox
                            id="active-project"
                            label="Proyek kapal"
                            className="w-52"
                            placeholder={
                                projects.length
                                    ? 'Pilih proyek kapal'
                                    : 'Belum ada proyek'
                            }
                            disabled={projects.length === 0}
                            value={String(project?.id ?? '')}
                            onValueChange={(value) =>
                                router.visit(
                                    workspace(
                                        section === 'baru'
                                            ? 'ringkasan'
                                            : section,
                                        { query: { project: value } },
                                    ),
                                )
                            }
                            options={projects.map(({ id, name }) => ({
                                value: String(id),
                                label: name,
                            }))}
                        />
                    </div>
                    <Link
                        className="ml-auto shrink-0 text-sm font-medium text-primary"
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
