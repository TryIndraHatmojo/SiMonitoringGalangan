import { Link, usePage } from '@inertiajs/react';
import {
    ChartNoAxesCombined,
    Layers,
    ListChecks,
    PanelsTopLeft,
    Ship,
} from 'lucide-react';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarHeader,
    SidebarContent,
    SidebarFooter,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, workspace } from '@/routes';
import { sections, type Monitoring } from '@/types/monitoring';

export function AppSidebar() {
    const { name, project, section, period } = usePage<Monitoring>().props;
    const links = [
        ['ringkasan', ChartNoAxesCombined],
        ['wbs', Layers],
        ['builder', PanelsTopLeft],
        ['input', ListChecks],
    ] as const;
    return (
        <Sidebar collapsible="icon">
            <SidebarHeader>
                <Link
                    href={dashboard()}
                    aria-label={name}
                    className="flex items-center gap-3 px-2 py-4 font-bold"
                >
                    <Ship
                        aria-hidden="true"
                        className="rounded-md bg-sidebar-primary p-1 text-sidebar-primary-foreground"
                        size={30}
                    />
                    <span className="min-w-0 truncate group-data-[collapsible=icon]:hidden">
                        {name}
                    </span>
                </Link>
            </SidebarHeader>
            <SidebarContent className="p-2">
                <SidebarMenu>
                    {links.map(([id, Icon]) => (
                        <SidebarMenuItem key={id}>
                            <SidebarMenuButton
                                asChild
                                className={
                                    id === 'wbs'
                                        ? 'h-auto min-h-8 py-1.5'
                                        : undefined
                                }
                                isActive={id === section}
                                tooltip={sections[id]}
                            >
                                <Link
                                    href={workspace(id, {
                                        query: {
                                            project: project?.id,
                                            period: period?.id,
                                        },
                                    })}
                                >
                                    <Icon />
                                    {sections[id]}
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ))}
                </SidebarMenu>
            </SidebarContent>
            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
