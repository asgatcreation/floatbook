<script setup lang="ts">
import { Link, Head, usePoll } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Banknote,
    Calculator,
    CheckCircle2,
    Circle,
    CircleAlert,
    Clock,
    Plus,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import Amount from '@/components/Amount.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import WeekChart from '@/components/WeekChart.vue';
import { useTenant } from '@/composables/useTenant';
import { useBusinessTime } from '@/lib/dates';
import { formatNaira } from '@/lib/money';
import { dashboard } from '@/routes';
import { index as outletsIndex } from '@/routes/outlets';
import { show as countDay } from '@/routes/reconciliation';
import { edit as security } from '@/routes/security';
import { index as staffIndex } from '@/routes/staff';
import {
    create as newTransaction,
    index as transactionsIndex,
    show as showTransaction,
} from '@/routes/transactions';

type Totals = {
    count: number;
    volume: number;
    charges: number;
    commissions: number;
    fees: number;
    expenses: number;
    profit: number;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: dashboard() }] },
});

const props = defineProps<{
    greetingName: string;
    businessName: string;
    today: string;
    view: 'business' | 'agent';
    checklist: { key: string; label: string; done: boolean }[] | null;
    totals: { today: Totals; yesterday: Totals } | null;
    week: (Totals & { date: string })[];
    mine: { count: number; volume: number } | null;
    outlets: {
        id: string;
        name: string;
        cash: number;
        wallets: number;
        low: number;
        count: number;
        volume: number;
        profit: number | null;
        day_status: 'submitted' | 'approved' | 'returned' | 'reopened' | null;
    }[];
    alerts: {
        tone: 'loss' | 'warning' | 'pending';
        text: string;
        href: string;
    }[];
    recent: {
        id: string;
        type_label: string;
        amount: number;
        profit: number;
        status: string;
        voided: boolean;
        terminal: string;
        color: string;
        outlet: string;
        agent: string;
        occurred_at: string;
    }[];
    updatedAt: string;
}>();

const { tenant, can } = useTenant();
const { format } = useBusinessTime();

// Live: fetch fresh figures every 30 seconds while the page is open.
usePoll(30_000, {
    only: [
        'totals',
        'week',
        'mine',
        'outlets',
        'alerts',
        'recent',
        'updatedAt',
    ],
});

const greeting = computed(() => {
    const hour = Number(
        new Intl.DateTimeFormat('en-GB', {
            hour: 'numeric',
            hourCycle: 'h23',
            timeZone: tenant.value?.business.timezone ?? 'Africa/Lagos',
        }).format(new Date()),
    );

    return hour < 12
        ? 'Good morning'
        : hour < 17
          ? 'Good afternoon'
          : 'Good evening';
});

const setupLinks: Record<string, ReturnType<typeof outletsIndex> | null> = {
    email: null,
    outlet: outletsIndex(),
    staff: staffIndex(),
    two_factor: security(),
};
const setupDone = computed(
    () => props.checklist?.filter((i) => i.done).length ?? 0,
);
const showChecklist = computed(
    () => props.checklist && setupDone.value < props.checklist.length,
);

const profitChange = computed(() =>
    props.totals
        ? props.totals.today.profit - props.totals.yesterday.profit
        : 0,
);

const dayStatus = {
    submitted: { text: 'Counted, to approve', tone: 'pending' },
    approved: { text: 'Day closed', tone: 'gain' },
    returned: { text: 'Recount needed', tone: 'loss' },
    reopened: { text: 'Reopened', tone: 'warning' },
} as const;

const alertIcon = { loss: CircleAlert, warning: CircleAlert, pending: Clock };
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <!-- Greeting -->
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">
                    {{ greeting }}, {{ greetingName }}
                </h1>
                <p class="text-muted-foreground">
                    {{ businessName }} · {{ tenant?.membership.role_label }}
                </p>
            </div>
            <p
                class="flex items-center gap-2 text-xs text-muted-foreground"
                aria-live="polite"
            >
                <span class="relative flex size-2">
                    <span
                        class="absolute inline-flex size-full animate-ping rounded-full bg-gain opacity-60 motion-reduce:hidden"
                    />
                    <span
                        class="relative inline-flex size-2 rounded-full bg-gain"
                    />
                </span>
                Live · updated {{ format('time', updatedAt) }}
            </p>
        </header>

        <!-- Setup checklist (owners, until done) -->
        <section
            v-if="showChecklist && checklist"
            aria-labelledby="setup-heading"
            class="rounded-2xl border bg-card p-5"
        >
            <div
                class="mb-3 flex flex-wrap items-baseline justify-between gap-2"
            >
                <h2 id="setup-heading" class="font-semibold">
                    Set up your business
                </h2>
                <span class="tabular text-sm text-muted-foreground"
                    >{{ setupDone }} of {{ checklist.length }} done</span
                >
            </div>
            <ul class="grid gap-2 sm:grid-cols-2">
                <li v-for="item in checklist" :key="item.key">
                    <component
                        :is="!item.done && setupLinks[item.key] ? Link : 'div'"
                        :href="
                            !item.done
                                ? (setupLinks[item.key] ?? undefined)
                                : undefined
                        "
                        class="flex min-h-11 items-center gap-3 rounded-xl border px-3 py-2 text-sm"
                        :class="{
                            'hover:bg-muted':
                                !item.done && setupLinks[item.key],
                        }"
                    >
                        <CheckCircle2
                            v-if="item.done"
                            class="size-5 text-gain"
                        />
                        <Circle v-else class="size-5 text-muted-foreground" />
                        <span
                            class="flex-1"
                            :class="{
                                'text-muted-foreground line-through': item.done,
                            }"
                            >{{ item.label }}</span
                        >
                        <ArrowRight
                            v-if="!item.done && setupLinks[item.key]"
                            class="size-4 text-muted-foreground"
                        />
                    </component>
                </li>
            </ul>
        </section>

        <!-- ================= AGENT ================= -->
        <template v-if="view === 'agent'">
            <Button
                v-if="can('record_transactions')"
                as-child
                class="h-14 bg-gold text-lg font-bold text-gold-foreground hover:bg-gold/90"
            >
                <Link :href="newTransaction()"
                    ><Plus class="size-5" /> New transaction</Link
                >
            </Button>

            <section
                v-if="mine"
                aria-label="Your day"
                class="grid grid-cols-2 gap-3"
            >
                <div class="rounded-2xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Your entries today
                    </p>
                    <p class="tabular mt-1 text-2xl font-bold">
                        {{ mine.count }}
                    </p>
                </div>
                <div class="rounded-2xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Your volume today
                    </p>
                    <p class="tabular mt-1 text-2xl font-bold">
                        <Amount :kobo="mine.volume" hide-kobo />
                    </p>
                </div>
            </section>
        </template>

        <!-- ================= BUSINESS ================= -->
        <template v-else-if="totals">
            <section
                aria-label="Today"
                class="grid grid-cols-2 gap-3 lg:grid-cols-4"
            >
                <div
                    class="col-span-2 rounded-2xl border bg-card p-5 lg:col-span-1"
                >
                    <p class="text-sm text-muted-foreground">Profit today</p>
                    <p class="tabular mt-1 text-3xl font-bold tracking-tight">
                        <Amount :kobo="totals.today.profit" hide-kobo />
                    </p>
                    <p
                        class="mt-2 flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <component
                            :is="
                                profitChange >= 0
                                    ? ArrowUpRight
                                    : ArrowDownRight
                            "
                            class="size-3.5"
                            :class="
                                profitChange >= 0 ? 'text-gain' : 'text-loss'
                            "
                            aria-hidden="true"
                        />
                        <span class="tabular">{{
                            formatNaira(Math.abs(profitChange), { trim: true })
                        }}</span>
                        {{ profitChange >= 0 ? 'more' : 'less' }} than yesterday
                    </p>
                </div>
                <div class="rounded-2xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Volume</p>
                    <p class="tabular mt-1 text-xl font-bold sm:text-2xl">
                        <Amount :kobo="totals.today.volume" hide-kobo />
                    </p>
                    <p class="tabular mt-1 text-xs text-muted-foreground">
                        {{ totals.today.count }} entries
                    </p>
                </div>
                <div class="rounded-2xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Earned</p>
                    <p class="tabular mt-1 text-xl font-bold sm:text-2xl">
                        <Amount
                            :kobo="
                                totals.today.charges + totals.today.commissions
                            "
                            hide-kobo
                        />
                    </p>
                    <p class="tabular mt-1 text-xs text-muted-foreground">
                        Fees <Amount :kobo="totals.today.fees" hide-kobo />
                    </p>
                </div>
                <div
                    class="col-span-2 rounded-2xl border bg-card p-4 sm:col-span-1"
                >
                    <p class="text-sm text-muted-foreground">Expenses</p>
                    <p class="tabular mt-1 text-xl font-bold sm:text-2xl">
                        <Amount :kobo="totals.today.expenses" hide-kobo />
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Taken off profit
                    </p>
                </div>
            </section>
        </template>

        <div
            class="grid gap-6"
            :class="{
                'xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]':
                    view === 'business',
            }"
        >
            <!-- Week chart -->
            <section
                v-if="view === 'business' && week.length"
                class="rounded-2xl border bg-card p-5"
                aria-labelledby="week-heading"
            >
                <div class="mb-2 flex items-baseline justify-between gap-2">
                    <h2 id="week-heading" class="font-semibold">
                        Profit, last 7 days
                    </h2>
                    <span class="tabular text-sm text-muted-foreground">
                        <Amount
                            :kobo="week.reduce((s, d) => s + d.profit, 0)"
                            hide-kobo
                        />
                        total
                    </span>
                </div>
                <WeekChart :days="week" :today="today" />
            </section>

            <!-- Needs attention -->
            <section
                class="rounded-2xl border bg-card"
                aria-labelledby="alerts-heading"
                :class="{ 'xl:order-none': true }"
            >
                <h2
                    id="alerts-heading"
                    class="border-b px-5 py-3 font-semibold"
                >
                    Needs attention
                </h2>
                <p
                    v-if="alerts.length === 0"
                    class="flex items-center gap-2 px-5 py-6 text-sm text-muted-foreground"
                >
                    <CheckCircle2 class="size-5 text-gain" /> All clear. Nothing
                    waiting on you.
                </p>
                <ul v-else class="divide-y">
                    <li v-for="(a, i) in alerts" :key="i">
                        <Link
                            :href="a.href"
                            class="flex items-start gap-3 px-5 py-3 text-sm hover:bg-muted/60"
                        >
                            <component
                                :is="alertIcon[a.tone]"
                                class="mt-0.5 size-4 shrink-0"
                                :class="{
                                    'text-loss': a.tone === 'loss',
                                    'text-warning': a.tone === 'warning',
                                    'text-pending': a.tone === 'pending',
                                }"
                            />
                            <span class="flex-1">{{ a.text }}</span>
                            <ArrowRight
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Outlets -->
        <section aria-labelledby="outlets-heading">
            <h2 id="outlets-heading" class="mb-3 font-semibold">
                {{ view === 'agent' ? 'Your outlet' : 'Outlets today' }}
            </h2>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <li
                    v-for="o in outlets"
                    :key="o.id"
                    class="flex flex-col gap-4 rounded-2xl border bg-card p-4"
                >
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-semibold">{{ o.name }}</h3>
                        <StatusPill
                            v-if="o.day_status"
                            :tone="dayStatus[o.day_status].tone"
                            >{{ dayStatus[o.day_status].text }}</StatusPill
                        >
                        <StatusPill v-else-if="o.low" tone="warning"
                            >{{ o.low }} low</StatusPill
                        >
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Banknote class="size-3.5" /> Cash
                            </dt>
                            <dd
                                class="tabular font-semibold"
                                :class="{ 'text-loss': o.cash < 0 }"
                            >
                                <Amount :kobo="o.cash" hide-kobo />
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <Wallet class="size-3.5" /> Wallets
                            </dt>
                            <dd class="tabular font-semibold">
                                <Amount :kobo="o.wallets" hide-kobo />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Volume today
                            </dt>
                            <dd class="tabular">
                                <Amount :kobo="o.volume" hide-kobo />
                                <span class="text-xs text-muted-foreground"
                                    >· {{ o.count }}
                                    {{
                                        o.count === 1 ? 'entry' : 'entries'
                                    }}</span
                                >
                            </dd>
                        </div>
                        <div v-if="o.profit !== null">
                            <dt class="text-xs text-muted-foreground">
                                Profit today
                            </dt>
                            <dd class="tabular">
                                <Amount :kobo="o.profit" hide-kobo />
                            </dd>
                        </div>
                    </dl>
                    <Link
                        v-if="
                            !o.day_status ||
                            o.day_status === 'returned' ||
                            o.day_status === 'reopened'
                        "
                        :href="countDay([o.id, today])"
                        class="mt-auto inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground"
                    >
                        <Calculator class="size-4" /> Count today
                    </Link>
                </li>
            </ul>
        </section>

        <!-- Recent activity -->
        <section
            v-if="recent.length"
            class="rounded-2xl border bg-card"
            aria-labelledby="recent-heading"
        >
            <div class="flex items-center justify-between border-b px-5 py-3">
                <h2 id="recent-heading" class="font-semibold">
                    {{
                        view === 'agent'
                            ? 'Your latest entries'
                            : 'Latest entries'
                    }}
                </h2>
                <Link
                    :href="transactionsIndex()"
                    class="text-sm text-muted-foreground hover:text-foreground"
                    >See all</Link
                >
            </div>
            <ul class="divide-y">
                <li v-for="r in recent" :key="r.id">
                    <Link
                        :href="showTransaction(r.id)"
                        class="flex items-center gap-3 px-5 py-3 text-sm hover:bg-muted/60"
                        :class="{ 'opacity-60': r.voided }"
                    >
                        <ProviderDot :color="r.color" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{
                                r.type_label
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                            >
                                {{ r.terminal }} ·
                                {{ view === 'agent' ? r.outlet : r.agent }} ·
                                {{ format('time', r.occurred_at) }}
                            </span>
                        </span>
                        <StatusPill v-if="r.voided">Voided</StatusPill>
                        <StatusPill
                            v-else-if="r.status !== 'successful'"
                            :tone="r.status === 'pending' ? 'pending' : 'loss'"
                            class="capitalize"
                            >{{ r.status }}</StatusPill
                        >
                        <span
                            class="tabular font-semibold"
                            :class="{ 'line-through': r.voided }"
                            ><Amount :kobo="r.amount" hide-kobo
                        /></span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
