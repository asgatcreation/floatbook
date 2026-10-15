<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Calculator,
    Check,
    CircleAlert,
    Clock,
    RotateCcw,
} from '@lucide/vue';
import ReconciliationController from '@/actions/App/Http/Controllers/ReconciliationController';
import Amount from '@/components/Amount.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/reconciliation';

type Day = {
    date: string;
    status: 'submitted' | 'approved' | 'returned' | 'reopened' | null;
    cash_variance: number | null;
    balances: boolean | null;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'End of day', href: index() }] },
});

const props = defineProps<{
    today: string;
    days: string[];
    outlets: { id: string; name: string; days: Day[] }[];
    shortages: {
        id: string;
        person: string;
        outlet: string;
        date: string;
        amount: number;
    }[];
    canSettle: boolean;
}>();

const weekday = (date: string) =>
    new Intl.DateTimeFormat('en-NG', {
        timeZone: 'UTC',
        weekday: 'short',
    }).format(new Date(`${date}T00:00:00Z`));
const dayMonth = (date: string) =>
    new Intl.DateTimeFormat('en-NG', {
        timeZone: 'UTC',
        day: 'numeric',
        month: 'short',
    }).format(new Date(`${date}T00:00:00Z`));

/** How a day looks: colour, icon and a word or two. */
function look(d: Day): { class: string; icon: unknown; text: string } {
    if (d.status === 'approved') {
        return d.balances
            ? {
                  class: 'border-gain/30 bg-gain-soft text-gain',
                  icon: Check,
                  text: 'Balanced',
              }
            : {
                  class: 'border-warning/30 bg-warning-soft text-warning',
                  icon: CircleAlert,
                  text: 'Difference',
              };
    }

    if (d.status === 'submitted') {
        return {
            class: 'border-pending/30 bg-pending-soft text-pending',
            icon: Clock,
            text: 'To approve',
        };
    }

    if (d.status === 'returned' || d.status === 'reopened') {
        return {
            class: 'border-loss/30 bg-loss-soft text-loss',
            icon: RotateCcw,
            text: 'Recount',
        };
    }

    return {
        class: 'border-dashed text-muted-foreground',
        icon: null,
        text: 'Not counted',
    };
}

function settle(id: string, outcome: 'recovered' | 'written_off'): void {
    if (
        outcome === 'written_off' &&
        !window.confirm('Write this shortage off? The business takes the loss.')
    ) {
        return;
    }

    router.post(
        ReconciliationController.settleShortage.url(id),
        { outcome },
        { preserveScroll: true },
    );
}

const todayOf = (o: (typeof props.outlets)[number]) =>
    o.days.find((d) => d.date === props.today);
</script>

<template>
    <Head title="End of day" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="End of day"
            description="At closing, count the drawer and read each terminal's balance. FloatBook compares it with the books."
        />

        <section
            v-if="outlets.length === 0"
            class="rounded-2xl border border-dashed bg-card p-10 text-center text-sm text-muted-foreground"
        >
            No outlets to count.
        </section>

        <section
            v-for="o in outlets"
            :key="o.id"
            class="rounded-2xl border bg-card p-4 sm:p-5"
            :aria-label="o.name"
        >
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ o.name }}</h2>
                <Button
                    v-if="
                        !todayOf(o)?.status ||
                        todayOf(o)?.status === 'returned' ||
                        todayOf(o)?.status === 'reopened'
                    "
                    as-child
                    class="bg-gold font-semibold text-gold-foreground hover:bg-gold/90"
                >
                    <Link :href="show([o.id, today])">
                        <Calculator class="size-4" /> Count today
                    </Link>
                </Button>
                <Button v-else as-child variant="outline">
                    <Link :href="show([o.id, today])">
                        Today's count <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </div>

            <ol class="grid grid-cols-4 gap-2 sm:grid-cols-7">
                <li v-for="d in o.days" :key="d.date">
                    <Link
                        :href="show([o.id, d.date])"
                        class="flex h-full flex-col gap-1 rounded-xl border px-2.5 py-2 text-xs transition hover:shadow-sm"
                        :class="look(d).class"
                    >
                        <span class="font-semibold text-foreground"
                            >{{ d.date === today ? 'Today' : weekday(d.date) }}
                        </span>
                        <span class="text-muted-foreground">{{
                            dayMonth(d.date)
                        }}</span>
                        <span
                            class="mt-auto flex items-center gap-1 font-medium"
                        >
                            <component
                                :is="look(d).icon"
                                v-if="look(d).icon"
                                class="size-3.5"
                            />
                            {{ look(d).text }}
                        </span>
                        <span
                            v-if="d.status === 'approved' && d.cash_variance"
                            class="tabular"
                            ><Amount :kobo="d.cash_variance" signed hide-kobo
                        /></span>
                    </Link>
                </li>
            </ol>
        </section>

        <section
            v-if="shortages.length"
            class="rounded-2xl border bg-card"
            aria-labelledby="owed-heading"
        >
            <h2 id="owed-heading" class="border-b px-5 py-3 font-semibold">
                Shortages still owed
            </h2>
            <ul class="divide-y">
                <li
                    v-for="s in shortages"
                    :key="s.id"
                    class="flex flex-wrap items-center gap-3 px-5 py-3 text-sm"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ s.person }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ s.outlet }} · {{ dayMonth(s.date) }}
                        </p>
                    </div>
                    <span class="tabular font-semibold"
                        ><Amount :kobo="s.amount"
                    /></span>
                    <template v-if="canSettle">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="settle(s.id, 'recovered')"
                            >Paid back</Button
                        >
                        <Button
                            size="sm"
                            variant="ghost"
                            class="text-muted-foreground"
                            @click="settle(s.id, 'written_off')"
                            >Write off</Button
                        >
                    </template>
                </li>
            </ul>
        </section>
    </div>
</template>
