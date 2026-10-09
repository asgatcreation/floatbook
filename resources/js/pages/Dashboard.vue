<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CheckCircle2, Circle } from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { useTenant } from '@/composables/useTenant';
import { dashboard } from '@/routes';
import { index as outlets } from '@/routes/outlets';
import { edit as security } from '@/routes/security';
import { index as staff } from '@/routes/staff';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const props = defineProps<{
    greetingName: string;
    businessName: string;
    checklist: { key: string; label: string; done: boolean }[] | null;
}>();

const { tenant } = useTenant();

const links: Record<string, ReturnType<typeof outlets> | null> = {
    email: null,
    outlet: outlets(),
    staff: staff(),
    two_factor: security(),
};

const doneCount = computed(
    () => props.checklist?.filter((item) => item.done).length ?? 0,
);

const greeting = computed(() => {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Good morning'
        : hour < 17
          ? 'Good afternoon'
          : 'Good evening';
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="`${greeting}, ${props.greetingName}`"
            :description="`${props.businessName} · ${tenant?.membership.role_label}`"
        />

        <section
            v-if="props.checklist"
            aria-labelledby="setup-heading"
            class="rounded-2xl border bg-card p-5"
        >
            <div
                class="mb-4 flex flex-wrap items-baseline justify-between gap-2"
            >
                <h2 id="setup-heading" class="text-lg font-semibold">
                    Set up your business
                </h2>
                <span class="tabular text-sm text-muted-foreground"
                    >{{ doneCount }} of {{ props.checklist.length }} done</span
                >
            </div>
            <div
                class="mb-4 h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                :aria-valuenow="doneCount"
                aria-valuemin="0"
                :aria-valuemax="props.checklist.length"
            >
                <div
                    class="h-full rounded-full bg-gold transition-all"
                    :style="{
                        width: `${(doneCount / props.checklist.length) * 100}%`,
                    }"
                />
            </div>
            <ul class="grid gap-2">
                <li v-for="item in props.checklist" :key="item.key">
                    <component
                        :is="!item.done && links[item.key] ? Link : 'div'"
                        :href="
                            !item.done
                                ? (links[item.key] ?? undefined)
                                : undefined
                        "
                        class="flex min-h-12 items-center gap-3 rounded-xl border px-3 py-2"
                        :class="{
                            'hover:bg-muted': !item.done && links[item.key],
                        }"
                    >
                        <CheckCircle2
                            v-if="item.done"
                            class="size-5 text-gain"
                        />
                        <Circle v-else class="size-5 text-muted-foreground" />
                        <span
                            class="flex-1 text-sm"
                            :class="{
                                'text-muted-foreground line-through': item.done,
                            }"
                            >{{ item.label }}</span
                        >
                        <ArrowRight
                            v-if="!item.done && links[item.key]"
                            class="size-4 text-muted-foreground"
                        />
                    </component>
                </li>
            </ul>
        </section>

        <section class="rounded-2xl border border-dashed bg-card p-6">
            <h2 class="font-semibold">Coming next</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Transactions, cash and wallet float, end-of-day counts and live
                reports will appear here.
            </p>
            <ul
                v-if="tenant?.membership.outlets.length"
                class="mt-4 flex flex-wrap gap-2"
            >
                <li
                    v-for="outlet in tenant.membership.outlets"
                    :key="outlet.id"
                    class="rounded-full bg-muted px-3 py-1 text-sm"
                >
                    {{ outlet.name }}
                </li>
            </ul>
        </section>
    </div>
</template>
