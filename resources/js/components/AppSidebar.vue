<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    LayoutGrid,
    Lock,
    ScrollText,
    Settings2,
    Store,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import BusinessSwitcher from '@/components/BusinessSwitcher.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useTenant } from '@/composables/useTenant';
import { dashboard, lock } from '@/routes';
import { index as auditLog } from '@/routes/audit-log';
import { edit as businessSettings } from '@/routes/business';
import { index as outlets } from '@/routes/outlets';
import { index as staff } from '@/routes/staff';
import type { NavItem } from '@/types';

const { can } = useTenant();

const mainNavItems = computed<NavItem[]>(() =>
    [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid, show: true },
        {
            title: 'Outlets',
            href: outlets(),
            icon: Store,
            show: can('manage_outlets'),
        },
        {
            title: 'Staff',
            href: staff(),
            icon: Users,
            show: can('manage_staff') || can('invite_agents'),
        },
        {
            title: 'Audit log',
            href: auditLog(),
            icon: ScrollText,
            show: can('view_audit_log'),
        },
        {
            title: 'Business settings',
            href: businessSettings(),
            icon: Settings2,
            show: can('manage_business'),
        },
    ]
        .filter((item) => item.show)
        .map(({ show: _show, ...item }) => item),
);

function lockScreen(): void {
    router.post(lock.url());
}
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader class="gap-3">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <BusinessSwitcher />
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        tooltip="Lock screen"
                        data-test="lock-button"
                        @click="lockScreen"
                    >
                        <Lock />
                        <span>Lock screen</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
