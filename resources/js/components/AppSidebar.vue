<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    LayoutGrid,
    Lock,
    Plus,
    Receipt,
    RotateCcw,
    ScrollText,
    Settings2,
    Store,
    Users,
    Wallet,
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
import { index as floatIndex } from '@/routes/float';
import { index as outlets } from '@/routes/outlets';
import { index as reversals } from '@/routes/reversals';
import { index as staff } from '@/routes/staff';
import {
    create as newTransaction,
    index as transactions,
} from '@/routes/transactions';
import type { NavItem } from '@/types';

const { can, tenant } = useTenant();

const mainNavItems = computed<NavItem[]>(() =>
    [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid, show: true },
        {
            title: 'Transactions',
            href: transactions(),
            icon: Receipt,
            show: true,
        },
        { title: 'Float', href: floatIndex(), icon: Wallet, show: true },
        {
            title: 'Reversals',
            href: reversals(),
            icon: RotateCcw,
            show: true,
            badge: tenant.value?.open_reversals,
        },
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
            <SidebarMenu v-if="can('record_transactions')">
                <SidebarMenuItem>
                    <SidebarMenuButton
                        as-child
                        tooltip="New transaction"
                        class="bg-gold font-semibold text-gold-foreground hover:bg-gold/90 hover:text-gold-foreground active:bg-gold/80 active:text-gold-foreground"
                    >
                        <Link
                            :href="newTransaction()"
                            data-test="nav-new-transaction"
                        >
                            <Plus />
                            <span>New transaction</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
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
