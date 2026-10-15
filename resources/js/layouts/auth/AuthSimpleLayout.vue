<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

// A sample of what the books look like, for the brand panel on wide screens.
const sample = [
    { label: 'Cash withdrawal', outlet: 'Ketu Main', amount: '₦10,000' },
    { label: 'Transfer', outlet: 'Ojota Junction', amount: '₦25,000' },
    { label: 'Airtime', outlet: 'Ketu Main', amount: '₦1,000' },
];
</script>

<template>
    <div
        class="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]"
    >
        <!-- Brand panel (wide screens only) -->
        <aside
            class="relative hidden overflow-hidden bg-[#10141b] p-10 text-white lg:flex lg:flex-col"
            aria-hidden="true"
        >
            <div
                class="pointer-events-none absolute -top-32 -right-32 size-[28rem] rounded-full bg-[radial-gradient(closest-side,rgb(232_163_23/0.22),transparent)]"
            />
            <Link
                :href="home()"
                class="relative flex w-fit items-center gap-2.5"
                tabindex="-1"
            >
                <AppLogoIcon class="size-9" />
                <span class="text-xl font-bold tracking-tight">FloatBook</span>
            </Link>

            <div class="relative my-auto max-w-md">
                <p
                    class="text-3xl leading-tight font-bold tracking-tight text-balance"
                >
                    Every naira at the counter, accounted for.
                </p>
                <p class="mt-3 text-white/65">
                    Transactions, cash and wallet float, reversals and
                    statements, for every outlet in one place.
                </p>

                <div
                    class="mt-10 rounded-2xl border border-white/10 bg-white/[0.04] p-5"
                >
                    <div class="flex items-baseline justify-between">
                        <p class="text-sm text-white/60">Today</p>
                        <p class="tabular text-sm font-semibold text-[#4ade80]">
                            +₦38,450 profit
                        </p>
                    </div>
                    <ul class="mt-4 divide-y divide-white/10">
                        <li
                            v-for="row in sample"
                            :key="row.label + row.outlet"
                            class="flex items-center justify-between py-2.5 text-sm"
                        >
                            <span>
                                {{ row.label }}
                                <span class="block text-xs text-white/50">{{
                                    row.outlet
                                }}</span>
                            </span>
                            <span class="tabular font-semibold">{{
                                row.amount
                            }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <p class="relative text-xs text-white/40">
                Designed and built by Akanji Oluwaseun Gabriel
            </p>
        </aside>

        <!-- Form -->
        <main
            class="flex flex-col items-center justify-center px-4 py-10 sm:px-6"
        >
            <div class="w-full max-w-md">
                <Link
                    :href="home()"
                    class="mx-auto mb-8 flex w-fit items-center gap-2.5 lg:hidden"
                >
                    <AppLogoIcon class="size-9" />
                    <span class="text-xl font-bold tracking-tight"
                        >FloatBook</span
                    >
                </Link>

                <div
                    class="rounded-2xl border bg-card p-6 shadow-sm sm:p-8 lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none dark:shadow-none"
                >
                    <div v-if="title || description" class="mb-7 space-y-1.5">
                        <h1
                            class="text-2xl font-bold tracking-tight sm:text-3xl"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>

                <p
                    class="mt-8 text-center text-xs text-muted-foreground lg:hidden"
                >
                    The back office for POS and agent-banking businesses.
                </p>
            </div>
        </main>
        <Toaster />
    </div>
</template>
