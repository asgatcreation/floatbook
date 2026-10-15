<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, Menu, Plus, Receipt, RotateCcw } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useTenant } from '@/composables/useTenant';
import { dashboard } from '@/routes';
import { index as reversals } from '@/routes/reversals';
import {
    create as newTransaction,
    index as transactions,
} from '@/routes/transactions';

/**
 * Bottom navigation for phones, where the sidebar is tucked away. The middle button is
 * the one an agent presses all day. Hidden on the entry and end-of-day screens, which have
 * their own action bar at the bottom.
 */
const page = usePage();
const { can, tenant } = useTenant();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { setOpenMobile } = useSidebar();

// Screens with their own action bar at the bottom.
const hidden = computed(() =>
    ['transactions/Create', 'reconciliation/Show'].includes(page.component),
);
const openReversals = computed(() => tenant.value?.open_reversals ?? 0);

const tab =
    'flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 text-[11px] font-medium text-muted-foreground aria-[current=page]:text-foreground';
</script>

<template>
    <!-- Keeps the last bit of each page clear of the bar. -->
    <div v-if="!hidden" class="h-20 md:hidden" aria-hidden="true" />
    <nav
        v-if="!hidden"
        class="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
        aria-label="Quick navigation"
    >
        <div class="mx-auto flex h-16 max-w-md items-stretch px-2">
            <Link
                :href="dashboard()"
                :class="tab"
                :aria-current="isCurrentUrl(dashboard()) ? 'page' : undefined"
            >
                <LayoutGrid class="size-5" aria-hidden="true" />
                Home
            </Link>
            <Link
                :href="transactions()"
                :class="tab"
                :aria-current="
                    isCurrentOrParentUrl(transactions()) ? 'page' : undefined
                "
            >
                <Receipt class="size-5" aria-hidden="true" />
                Transactions
            </Link>

            <div
                v-if="can('record_transactions')"
                class="flex flex-1 items-center justify-center"
            >
                <Link
                    :href="newTransaction()"
                    class="-mt-6 grid size-14 place-items-center rounded-2xl bg-gold text-gold-foreground shadow-lg ring-4 shadow-black/15 ring-background"
                    aria-label="New transaction"
                    data-test="tab-new-transaction"
                >
                    <Plus
                        class="size-7"
                        stroke-width="2.5"
                        aria-hidden="true"
                    />
                </Link>
            </div>

            <Link
                :href="reversals()"
                :class="tab"
                :aria-current="
                    isCurrentOrParentUrl(reversals()) ? 'page' : undefined
                "
            >
                <span class="relative">
                    <RotateCcw class="size-5" aria-hidden="true" />
                    <span
                        v-if="openReversals"
                        class="tabular absolute -top-1.5 -right-2.5 min-w-4 rounded-full bg-loss px-1 text-center text-[10px] leading-4 font-bold text-white"
                        :aria-label="`${openReversals} open`"
                        >{{ openReversals }}</span
                    >
                </span>
                Reversals
            </Link>
            <button type="button" :class="tab" @click="setOpenMobile(true)">
                <Menu class="size-5" aria-hidden="true" />
                More
            </button>
        </div>
    </nav>
</template>
