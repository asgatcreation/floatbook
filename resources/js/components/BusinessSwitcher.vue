<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Store } from '@lucide/vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useTenant } from '@/composables/useTenant';
import BusinessSwitchController from '@/actions/App/Http/Controllers/BusinessSwitchController';

const { tenant } = useTenant();

function switchTo(id: string): void {
    if (id === tenant.value?.business.id) {
        return;
    }

    router.post(BusinessSwitchController.url(), { business_id: id });
}
</script>

<template>
    <SidebarMenu v-if="tenant">
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton
                        size="lg"
                        class="border border-sidebar-border bg-sidebar-accent/60"
                        data-test="business-switcher"
                    >
                        <Store class="size-4 text-muted-foreground" />
                        <div class="grid flex-1 text-left leading-tight">
                            <span
                                class="text-[11px] tracking-wider text-muted-foreground uppercase"
                                >{{ tenant.membership.role_label }}</span
                            >
                            <span class="truncate text-sm font-semibold">{{
                                tenant.business.name
                            }}</span>
                        </div>
                        <ChevronsUpDown
                            v-if="tenant.businesses.length > 1"
                            class="ml-auto size-4"
                        />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    v-if="tenant.businesses.length > 1"
                    class="w-(--reka-dropdown-menu-trigger-width) min-w-56"
                    align="start"
                >
                    <DropdownMenuLabel class="text-xs text-muted-foreground"
                        >Switch business</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="item in tenant.businesses"
                        :key="item.id"
                        class="cursor-pointer"
                        @select="switchTo(item.id)"
                    >
                        <div class="grid flex-1">
                            <span class="font-medium">{{ item.name }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                item.role
                            }}</span>
                        </div>
                        <Check
                            v-if="item.id === tenant.business.id"
                            class="size-4"
                        />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
