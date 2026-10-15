<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    ChevronDown,
    Clock,
    Phone,
    RotateCcw,
    ShieldAlert,
    X,
} from '@lucide/vue';
import { reactive, ref } from 'vue';
import ReversalController from '@/actions/App/Http/Controllers/ReversalController';
import Amount from '@/components/Amount.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/reversals';
import { show as showTransaction } from '@/routes/transactions';

type Case = {
    id: string;
    transaction_id: string;
    type_label: string;
    amount: number;
    customer_name: string | null;
    customer_phone: string | null;
    provider: { name: string; color: string };
    outlet: string;
    reference: string | null;
    cash_paid: boolean;
    status: string;
    status_label: string;
    provider_ticket: string | null;
    outcome: string | null;
    opened_at: string;
    due_at: string;
    closed_at: string | null;
    overdue: boolean;
    updates: {
        by: string;
        to_status: string | null;
        note: string | null;
        at: string;
    }[];
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reversals', href: index() }] },
});

const props = defineProps<{
    view: 'open' | 'closed';
    cases: Case[];
    counts: { open: number; overdue: number; owed: number };
    canWriteOff: boolean;
}>();

// Per-case form state, created on first use.
const forms = reactive<
    Record<
        string,
        {
            note: string;
            ticket: string;
            outcome: string;
            errors: Record<string, string>;
        }
    >
>({});
const busy = ref<string | null>(null);
const expanded = ref<string | null>(null);

function form(c: Case) {
    return (forms[c.id] ??= {
        note: '',
        ticket: c.provider_ticket ?? '',
        outcome: '',
        errors: {},
    });
}

function send(c: Case, status: string | null): void {
    const f = form(c);

    if (c.cash_paid && status === 'reversed' && !f.outcome) {
        f.errors = {
            cash_outcome:
                'Did the customer return the cash? Choose below first.',
        };

        return;
    }

    router.post(
        ReversalController.update.url(c.id),
        {
            status,
            note: f.note || null,
            provider_ticket: f.ticket || null,
            cash_outcome: f.outcome || null,
        },
        {
            preserveScroll: true,
            onStart: () => (busy.value = c.id + (status ?? 'note')),
            onFinish: () => (busy.value = null),
            onError: (e) => (f.errors = e),
            onSuccess: () => {
                f.note = '';
                f.errors = {};
            },
        },
    );
}

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

function ago(iso: string): string {
    const hours = Math.round(
        (new Date(iso).getTime() - Date.now()) / 3_600_000,
    );

    return Math.abs(hours) < 24
        ? relative.format(hours, 'hour')
        : relative.format(Math.round(hours / 24), 'day');
}

function dueText(c: Case): string {
    const hours = Math.round(
        (new Date(c.due_at).getTime() - Date.now()) / 3_600_000,
    );

    if (hours < 0) {
        const late = Math.abs(hours);

        return late < 24
            ? `${late} h overdue`
            : `${Math.round(late / 24)} day${late >= 36 ? 's' : ''} overdue`;
    }

    return hours < 24
        ? `Due in ${hours} h`
        : `Due in ${Math.round(hours / 24)} day${hours >= 36 ? 's' : ''}`;
}

const when = new Intl.DateTimeFormat('en-NG', {
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
});
const closedTone = (s: string) =>
    s === 'resolved_successful' || s === 'reversed'
        ? 'gain'
        : s === 'written_off'
          ? 'loss'
          : 'neutral';
</script>

<template>
    <Head title="Reversals" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Reversals"
            description="Customers waiting on a failed or pending transaction. Follow each one up until the provider answers."
        />

        <section aria-label="Summary" class="grid grid-cols-3 gap-3">
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Open</p>
                <p class="tabular mt-1 text-2xl font-bold">{{ counts.open }}</p>
            </div>
            <div
                class="rounded-2xl border bg-card p-4"
                :class="{ 'border-loss/40 bg-loss-soft/40': counts.overdue }"
            >
                <p class="text-sm text-muted-foreground">Overdue</p>
                <p
                    class="tabular mt-1 text-2xl font-bold"
                    :class="{ 'text-loss': counts.overdue }"
                >
                    {{ counts.overdue }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    Customers waiting on
                </p>
                <p class="mt-1 text-2xl font-bold">
                    <Amount :kobo="counts.owed" hide-kobo />
                </p>
            </div>
        </section>

        <nav
            class="flex w-fit gap-1 rounded-xl border bg-card p-1"
            aria-label="Show"
        >
            <Link
                v-for="v in ['open', 'closed'] as const"
                :key="v"
                :href="index({ query: v === 'open' ? {} : { view: v } })"
                class="rounded-lg px-4 py-1.5 text-sm font-medium capitalize"
                :class="
                    view === v
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                preserve-scroll
                >{{ v }}</Link
            >
        </nav>

        <div
            v-if="cases.length === 0"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-14 text-center"
        >
            <span
                class="grid size-12 place-items-center rounded-full bg-gain-soft text-gain"
                ><Check class="size-6"
            /></span>
            <h2 class="text-lg font-semibold">
                {{
                    view === 'open'
                        ? 'No one is waiting'
                        : 'No closed cases yet'
                }}
            </h2>
            <p class="max-w-sm text-sm text-muted-foreground">
                When a transaction is saved as pending or failed, a case opens
                here automatically so it isn't forgotten.
            </p>
        </div>

        <ul v-else class="grid gap-3">
            <li
                v-for="c in cases"
                :key="c.id"
                class="rounded-2xl border bg-card"
                :class="{ 'border-loss/40': c.overdue }"
            >
                <div class="flex flex-wrap items-start gap-4 p-4">
                    <div class="min-w-0 flex-1 basis-64">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-xl font-bold tracking-tight">
                                <Amount :kobo="c.amount" />
                            </p>
                            <StatusPill
                                v-if="view === 'open'"
                                :tone="c.overdue ? 'loss' : 'pending'"
                            >
                                <AlertTriangle
                                    v-if="c.overdue"
                                    class="size-3"
                                /><Clock v-else class="size-3" />
                                {{ dueText(c) }}
                            </StatusPill>
                            <StatusPill v-else :tone="closedTone(c.status)">{{
                                c.status_label
                            }}</StatusPill>
                            <StatusPill
                                v-if="c.status === 'escalated'"
                                tone="warning"
                                ><ShieldAlert class="size-3" />
                                Escalated</StatusPill
                            >
                            <StatusPill v-if="c.cash_paid" tone="warning"
                                >Cash already paid</StatusPill
                            >
                        </div>
                        <p class="mt-1 text-sm">
                            <span class="font-medium">{{
                                c.customer_name ?? 'Customer'
                            }}</span>
                            <a
                                v-if="c.customer_phone"
                                :href="`tel:${c.customer_phone}`"
                                class="ml-2 inline-flex items-center gap-1 text-pending underline-offset-2 hover:underline"
                            >
                                <Phone class="size-3.5" />
                                {{ c.customer_phone }}
                            </a>
                        </p>
                        <p
                            class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"
                        >
                            <ProviderDot :color="c.provider.color" />
                            {{ c.type_label }} · {{ c.provider.name }} ·
                            {{ c.outlet }} · opened {{ ago(c.opened_at) }}
                            <template v-if="c.reference">
                                ·
                                <span class="font-mono">{{
                                    c.reference
                                }}</span></template
                            >
                            <template v-if="c.provider_ticket">
                                · ticket
                                <span class="font-mono">{{
                                    c.provider_ticket
                                }}</span></template
                            >
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Link
                            :href="showTransaction(c.transaction_id)"
                            class="text-sm text-muted-foreground underline-offset-2 hover:underline"
                            >Transaction</Link
                        >
                        <Button
                            variant="ghost"
                            size="sm"
                            :aria-expanded="expanded === c.id"
                            @click="expanded = expanded === c.id ? null : c.id"
                        >
                            {{ view === 'open' ? 'Update' : 'History' }}
                            <ChevronDown
                                class="size-4 transition"
                                :class="{ 'rotate-180': expanded === c.id }"
                            />
                        </Button>
                    </div>
                </div>

                <div
                    v-if="expanded === c.id"
                    class="grid gap-4 border-t p-4 lg:grid-cols-2"
                >
                    <ol class="grid gap-3 text-sm" aria-label="History">
                        <li
                            v-for="(u, i) in c.updates"
                            :key="i"
                            class="flex gap-3"
                        >
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full bg-muted-foreground/40"
                                aria-hidden="true"
                            />
                            <div>
                                <p>
                                    <span class="font-medium">{{ u.by }}</span>
                                    <span
                                        v-if="u.to_status"
                                        class="text-muted-foreground"
                                    >
                                        · {{ u.to_status }}</span
                                    >
                                </p>
                                <p v-if="u.note" class="text-muted-foreground">
                                    {{ u.note }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ when.format(new Date(u.at)) }}
                                </p>
                            </div>
                        </li>
                    </ol>

                    <form
                        v-if="view === 'open'"
                        class="grid content-start gap-3"
                        @submit.prevent="send(c, null)"
                    >
                        <div class="grid gap-1.5">
                            <label
                                :for="`note-${c.id}`"
                                class="text-sm font-medium"
                                >Note</label
                            >
                            <textarea
                                :id="`note-${c.id}`"
                                v-model="form(c).note"
                                rows="2"
                                class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                placeholder="e.g. Called the customer; provider says 24 hours"
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <label
                                :for="`ticket-${c.id}`"
                                class="text-sm font-medium"
                                >Provider complaint / ticket number</label
                            >
                            <Input
                                :id="`ticket-${c.id}`"
                                v-model="form(c).ticket"
                                class="font-mono"
                            />
                        </div>

                        <fieldset
                            v-if="c.cash_paid"
                            class="grid gap-1.5 rounded-xl bg-warning-soft p-3 text-sm"
                        >
                            <legend class="sr-only">
                                What happened to the cash
                            </legend>
                            <p class="font-medium text-warning">
                                The customer already got cash. If the provider
                                reverses it:
                            </p>
                            <label class="flex items-center gap-2"
                                ><input
                                    v-model="form(c).outcome"
                                    type="radio"
                                    value="cash_recovered"
                                    class="accent-[var(--primary)]"
                                />
                                They returned the cash</label
                            >
                            <label class="flex items-center gap-2"
                                ><input
                                    v-model="form(c).outcome"
                                    type="radio"
                                    value="written_off"
                                    class="accent-[var(--primary)]"
                                />
                                We could not get it back (loss)</label
                            >
                            <InputError
                                :message="form(c).errors.cash_outcome"
                            />
                        </fieldset>
                        <InputError
                            :message="
                                form(c).errors.status ?? form(c).errors.note
                            "
                        />

                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                :disabled="busy !== null"
                            >
                                <Spinner v-if="busy === c.id + 'note'" /> Save
                                note
                            </Button>
                            <Button
                                v-if="c.status !== 'escalated'"
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="busy !== null"
                                @click="send(c, 'escalated')"
                            >
                                <ShieldAlert class="size-4" /> Escalated
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                :disabled="busy !== null"
                                @click="send(c, 'resolved_successful')"
                            >
                                <Check class="size-4" /> It went through
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="busy !== null"
                                @click="send(c, 'reversed')"
                            >
                                <RotateCcw class="size-4" /> Customer refunded
                            </Button>
                            <Button
                                v-if="canWriteOff"
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-loss"
                                :disabled="busy !== null"
                                @click="send(c, 'written_off')"
                            >
                                <X class="size-4" /> Write off
                            </Button>
                        </div>
                    </form>
                </div>
            </li>
        </ul>
    </div>
</template>
