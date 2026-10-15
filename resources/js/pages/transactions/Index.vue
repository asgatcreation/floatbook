<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Plus,
    Receipt,
    Search,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Amount from '@/components/Amount.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/composables/useTenant';
import { create, index, show } from '@/routes/transactions';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
type Row = {
    id: string;
    type: string;
    type_label: string;
    flow: 'cash_out' | 'cash_in' | 'service';
    amount: number;
    profit: number;
    status: 'successful' | 'pending' | 'failed' | 'reversed';
    voided: boolean;
    reference: string | null;
    customer_name: string | null;
    customer_phone: string | null;
    provider: { name: string; color: string } | null;
    outlet: string | null;
    agent: string | null;
    occurred_at: string;
};

type Filters = {
    date: string;
    outlet?: string | null;
    status?: string | null;
    type?: string | null;
    search?: string | null;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Transactions', href: index() }] },
});

const props = defineProps<{
    transactions: {
        data: Row[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
    };
    summary: {
        count: number;
        volume: number;
        charges: number;
        commissions: number;
        fees: number;
        profit: number;
        pending: number;
    };
    filters: Filters;
    outlets: { id: string; name: string }[];
    types: { value: string; label: string }[];
    canVoid: boolean;
}>();

const { can } = useTenant();
const search = ref(props.filters.search ?? '');

function apply(changes: Partial<Filters>): void {
    const query = { ...props.filters, ...changes };
    const clean = Object.fromEntries(
        Object.entries(query).filter(
            ([, v]) => v !== null && v !== '' && v !== undefined,
        ),
    );

    router.get(
        index.url({ query: clean as Record<string, string> }),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

const statuses = [
    { value: null, label: 'All' },
    { value: 'successful', label: 'Successful' },
    { value: 'pending', label: 'Pending' },
    { value: 'failed', label: 'Failed' },
] as const;

const today = new Intl.DateTimeFormat('en-CA', { timeZone }).format(new Date());
const dateLabel = computed(() => {
    if (props.filters.date === today) {
        return 'Today';
    }

    return new Intl.DateTimeFormat('en-NG', {
        timeZone,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(`${props.filters.date}T12:00:00`));
});

const time = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
});
const tone = (r: Row) =>
    r.voided
        ? 'neutral'
        : r.status === 'successful'
          ? 'gain'
          : r.status === 'pending'
            ? 'pending'
            : 'loss';

function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}
</script>

<template>
    <Head title="Transactions" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :title="`Transactions · ${dateLabel}`"
            description="Every entry is final once saved. Mistakes are voided, never deleted."
        >
            <template #actions>
                <Button v-if="can('record_transactions')" as-child>
                    <Link :href="create()"
                        ><Plus class="size-4" /> New transaction</Link
                    >
                </Button>
            </template>
        </PageHeader>

        <!-- Summary -->
        <section
            aria-label="Summary"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Volume</p>
                <p class="mt-1 text-2xl font-bold tracking-tight">
                    <Amount :kobo="summary.volume" hide-kobo />
                </p>
                <p class="tabular mt-0.5 text-xs text-muted-foreground">
                    {{ summary.count }}
                    {{ summary.count === 1 ? 'entry' : 'entries' }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Profit</p>
                <p
                    class="mt-1 text-2xl font-bold tracking-tight"
                    :class="summary.profit >= 0 ? 'text-gain' : 'text-loss'"
                >
                    <Amount :kobo="summary.profit" signed />
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Charges + commissions − fees
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Charges collected</p>
                <p class="mt-1 text-2xl font-bold tracking-tight">
                    <Amount :kobo="summary.charges" />
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Fees <Amount :kobo="summary.fees" /> · Comm.
                    <Amount :kobo="summary.commissions" />
                </p>
            </div>
            <button
                type="button"
                class="rounded-2xl border bg-card p-4 text-left transition hover:border-pending/50"
                @click="apply({ status: 'pending' })"
            >
                <p class="text-sm text-muted-foreground">Waiting on provider</p>
                <p
                    class="tabular mt-1 text-2xl font-bold tracking-tight"
                    :class="{ 'text-pending': summary.pending }"
                >
                    {{ summary.pending }}
                </p>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ summary.pending ? 'Tap to review' : 'All settled' }}
                </p>
            </button>
        </section>

        <!-- Filters -->
        <section aria-label="Filters" class="flex flex-wrap items-center gap-2">
            <label class="sr-only" for="date">Date</label>
            <Input
                id="date"
                type="date"
                class="h-10 w-auto rounded-xl bg-card"
                :model-value="filters.date"
                :max="today"
                @update:model-value="(v) => apply({ date: String(v) })"
            />
            <select
                v-if="outlets.length > 1"
                class="h-10 rounded-xl border bg-card px-3 text-sm"
                aria-label="Outlet"
                :value="filters.outlet ?? ''"
                @change="
                    apply({
                        outlet:
                            ($event.target as HTMLSelectElement).value || null,
                    })
                "
            >
                <option value="">All outlets</option>
                <option v-for="o in outlets" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select>
            <select
                class="h-10 rounded-xl border bg-card px-3 text-sm"
                aria-label="Type"
                :value="filters.type ?? ''"
                @change="
                    apply({
                        type:
                            ($event.target as HTMLSelectElement).value || null,
                    })
                "
            >
                <option value="">All types</option>
                <option v-for="t in types" :key="t.value" :value="t.value">
                    {{ t.label }}
                </option>
            </select>
            <div
                class="flex gap-1 rounded-xl border bg-card p-1"
                role="group"
                aria-label="Status"
            >
                <button
                    v-for="s in statuses"
                    :key="s.label"
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                    :class="
                        (filters.status ?? null) === s.value
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    :aria-pressed="(filters.status ?? null) === s.value"
                    @click="apply({ status: s.value })"
                >
                    {{ s.label }}
                </button>
            </div>
            <form
                class="relative min-w-48 flex-1"
                role="search"
                @submit.prevent="apply({ search })"
            >
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    class="h-10 rounded-xl bg-card pl-9"
                    placeholder="Reference, phone or name"
                    aria-label="Search"
                />
            </form>
        </section>

        <!-- List -->
        <section aria-label="Transactions" class="rounded-2xl border bg-card">
            <div
                v-if="transactions.data.length === 0"
                class="flex flex-col items-center gap-3 px-6 py-14 text-center"
            >
                <span
                    class="grid size-12 place-items-center rounded-full bg-gold/15 text-gold"
                >
                    <Receipt class="size-6" />
                </span>
                <h2 class="text-lg font-semibold">No transactions here yet</h2>
                <p class="max-w-sm text-sm text-muted-foreground">
                    {{
                        filters.status || filters.type || filters.search
                            ? 'Nothing matches these filters.'
                            : 'Entries recorded at the counter appear here the moment they are saved.'
                    }}
                </p>
                <Button
                    v-if="can('record_transactions') && !filters.status"
                    as-child
                    variant="outline"
                >
                    <Link :href="create()"
                        ><Sparkles class="size-4" /> Record the first one</Link
                    >
                </Button>
            </div>

            <ul v-else class="divide-y">
                <li v-for="r in transactions.data" :key="r.id">
                    <Link
                        :href="show(r.id)"
                        class="flex items-center gap-3 px-4 py-3 transition hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                    >
                        <span
                            class="grid size-10 shrink-0 place-items-center rounded-full"
                            :class="
                                r.flow === 'cash_out'
                                    ? 'bg-pending-soft text-pending'
                                    : 'bg-gain-soft text-gain'
                            "
                            aria-hidden="true"
                        >
                            <ArrowUpRight
                                v-if="r.flow === 'cash_out'"
                                class="size-5"
                            />
                            <ArrowDownLeft v-else class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate font-medium"
                                :class="{ 'line-through opacity-60': r.voided }"
                            >
                                {{ r.type_label }}
                                <span
                                    v-if="r.customer_name"
                                    class="font-normal text-muted-foreground"
                                    >· {{ r.customer_name }}</span
                                >
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
                            >
                                <ProviderDot :color="r.provider?.color" />
                                <span>{{ r.provider?.name }}</span>
                                <span
                                    >·
                                    {{
                                        time.format(new Date(r.occurred_at))
                                    }}</span
                                >
                                <span v-if="outlets.length > 1"
                                    >· {{ r.outlet }}</span
                                >
                                <span class="hidden sm:inline"
                                    >· {{ r.agent }}</span
                                >
                                <span
                                    v-if="r.reference"
                                    class="hidden font-mono md:inline"
                                    >· {{ r.reference }}</span
                                >
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span
                                class="font-semibold"
                                :class="{ 'line-through opacity-60': r.voided }"
                                ><Amount :kobo="r.amount"
                            /></span>
                            <StatusPill
                                v-if="r.voided || r.status !== 'successful'"
                                :tone="tone(r)"
                            >
                                {{ r.voided ? 'Voided' : r.status }}
                            </StatusPill>
                            <span v-else class="text-xs text-gain"
                                ><Amount :kobo="r.profit" signed
                            /></span>
                        </div>
                    </Link>
                </li>
            </ul>
        </section>

        <nav
            v-if="transactions.links.length > 3"
            class="flex flex-wrap gap-1"
            aria-label="Pages"
        >
            <template v-for="link in transactions.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="min-w-9 rounded-lg border px-3 py-1.5 text-center text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-card'
                    "
                    >{{ pageLabel(link.label) }}</Link
                >
            </template>
        </nav>
    </div>
</template>
