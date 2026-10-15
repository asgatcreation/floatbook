<script setup lang="ts">
import type { FormDataConvertible } from '@inertiajs/core';
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Banknote,
    CircleAlert,
    CircleCheck,
    Coins,
    Lock,
    Minus,
    Plus,
    RotateCcw,
    Wallet,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import ReconciliationController from '@/actions/App/Http/Controllers/ReconciliationController';
import Amount from '@/components/Amount.vue';
import InputError from '@/components/InputError.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useBusinessTime } from '@/lib/dates';
import { formatNaira, parseNaira } from '@/lib/money';
import { index, show } from '@/routes/reconciliation';

type WalletRow = {
    id: string;
    label: string;
    color: string;
    terminals: string[];
    expected: number;
    actual: number;
    variance: number;
};
type Rec = {
    id: string;
    status: 'submitted' | 'approved' | 'returned' | 'reopened';
    status_label: string;
    expected_cash: number;
    counted_cash: number;
    cash_variance: number;
    denominations: Record<string, number>;
    note: string | null;
    balances: boolean;
    submitted_by: string;
    submitted_at: string;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    wallets: WalletRow[];
    shortages: { id: string; person: string; amount: number; status: string }[];
};

const props = defineProps<{
    outlet: { id: string; name: string };
    date: string;
    isToday: boolean;
    notes: number[];
    counting: boolean;
    canCount: boolean;
    walletsToCount: {
        id: string;
        label: string;
        color: string;
        terminals: string[];
    }[];
    reconciliation: Rec | null;
    staff: { id: string; name: string; role: string }[];
    canDecide: boolean;
    canSendBack: boolean;
    canReopen: boolean;
    canSettle: boolean;
}>();

const { format } = useBusinessTime();

const dayLabel = computed(() =>
    new Intl.DateTimeFormat('en-NG', {
        timeZone: 'UTC',
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(`${props.date}T00:00:00Z`)),
);

setLayoutProps({
    breadcrumbs: [
        { title: 'End of day', href: index() },
        { title: props.outlet.name, href: show([props.outlet.id, props.date]) },
    ],
});

// ---- Counting --------------------------------------------------------------
const counts = reactive<Record<string, number>>(
    Object.fromEntries(props.notes.map((n) => [String(n), 0])),
);
const coinsText = ref('');
const coins = computed(() => parseNaira(coinsText.value) ?? 0);
const walletText = reactive<Record<string, string>>(
    Object.fromEntries(props.walletsToCount.map((w) => [w.id, ''])),
);

const counted = computed(
    () =>
        props.notes.reduce(
            (sum, n) => sum + (counts[String(n)] || 0) * n * 100,
            0,
        ) + coins.value,
);
const walletsFilled = computed(() =>
    props.walletsToCount.every((w) => parseNaira(walletText[w.id]) !== null),
);

function step(note: number, by: number): void {
    counts[String(note)] = Math.max(0, (counts[String(note)] || 0) + by);
}

function payload(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        denominations: { ...counts, coins: coins.value },
        wallets: Object.fromEntries(
            props.walletsToCount.map((w) => [
                w.id,
                parseNaira(walletText[w.id]) ?? 0,
            ]),
        ),
    };
}

// ---- Deciding --------------------------------------------------------------
const rec = computed(() => props.reconciliation);
const shortfall = computed(() => Math.max(0, -(rec.value?.cash_variance ?? 0)));
const assign = reactive<Record<string, string>>({});
const assigned = computed(() =>
    Object.values(assign).reduce((sum, v) => sum + (parseNaira(v) ?? 0), 0),
);

function approvePayload(
    data: Record<string, FormDataConvertible>,
): Record<string, FormDataConvertible> {
    return {
        ...data,
        shortages: Object.fromEntries(
            Object.entries(assign)
                .map(([id, v]) => [id, parseNaira(v) ?? 0])
                .filter(([, kobo]) => Number(kobo) > 0),
        ),
    };
}

/** The drawer and each wallet: what the books said, what was counted, the difference. */
const rows = computed(() =>
    rec.value
        ? [
              {
                  key: 'cash',
                  label: 'Cash in drawer',
                  color: null as string | null,
                  expected: rec.value.expected_cash,
                  actual: rec.value.counted_cash,
                  variance: rec.value.cash_variance,
              },
              ...rec.value.wallets.map((w) => ({ key: w.id, ...w })),
          ]
        : [],
);

const sendBackOpen = ref(false);
const reopenOpen = ref(false);

const tone = (v: number) => (v === 0 ? 'gain' : v < 0 ? 'loss' : 'warning');
const statusTone = (s: Rec['status']) =>
    ({
        submitted: 'pending',
        approved: 'gain',
        returned: 'loss',
        reopened: 'warning',
    })[s] as 'pending' | 'gain' | 'loss' | 'warning';

function settle(id: string, outcome: 'recovered' | 'written_off'): void {
    router.post(
        ReconciliationController.settleShortage.url(id),
        { outcome },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="`End of day · ${outlet.name}`" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    :href="index()"
                    class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" /> All days
                </Link>
                <h1 class="text-2xl font-bold tracking-tight">
                    {{ outlet.name }}
                </h1>
                <p class="text-muted-foreground">
                    {{ isToday ? 'Today, ' : '' }}{{ dayLabel }}
                </p>
            </div>
            <StatusPill
                v-if="rec && !counting"
                :tone="statusTone(rec.status)"
                class="mt-7"
                >{{ rec.status_label }}</StatusPill
            >
        </div>

        <!-- Sent back or reopened: why -->
        <div
            v-if="rec && counting && rec.decision_note"
            class="flex gap-3 rounded-2xl border border-loss/30 bg-loss-soft px-4 py-3 text-sm"
        >
            <RotateCcw class="mt-0.5 size-4 shrink-0 text-loss" />
            <p>
                <strong>{{ rec.decided_by }}</strong> sent this back:
                {{ rec.decision_note }}
            </p>
        </div>

        <!-- ================= COUNT ================= -->
        <Form
            v-if="counting && canCount"
            v-bind="ReconciliationController.submit.form([outlet.id, date])"
            :transform="payload"
            class="flex flex-col gap-5"
            #default="{ errors, processing }"
        >
            <p class="text-sm text-muted-foreground">
                Count what is actually in the shop. You'll see what the books
                expected once you submit.
            </p>

            <!-- Cash -->
            <section class="rounded-2xl border bg-card">
                <h2
                    class="flex items-center gap-2 border-b px-5 py-3 font-semibold"
                >
                    <Banknote class="size-4 text-muted-foreground" /> Cash in
                    the drawer
                </h2>
                <ul class="divide-y">
                    <li
                        v-for="n in notes"
                        :key="n"
                        class="flex items-center gap-3 px-5 py-2"
                    >
                        <span class="tabular w-16 font-semibold"
                            >₦{{ n.toLocaleString('en-NG') }}</span
                        >
                        <span class="text-muted-foreground" aria-hidden="true"
                            >×</span
                        >
                        <div class="flex items-center gap-1">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="size-9"
                                :aria-label="`One less ₦${n} note`"
                                @click="step(n, -1)"
                            >
                                <Minus class="size-4" />
                            </Button>
                            <Input
                                v-model.number="counts[String(n)]"
                                type="number"
                                inputmode="numeric"
                                min="0"
                                class="tabular h-9 w-20 text-center"
                                :aria-label="`Number of ₦${n} notes`"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="size-9"
                                :aria-label="`One more ₦${n} note`"
                                @click="step(n, 1)"
                            >
                                <Plus class="size-4" />
                            </Button>
                        </div>
                        <span
                            class="tabular ml-auto text-right text-sm"
                            :class="
                                counts[String(n)]
                                    ? 'font-medium'
                                    : 'text-muted-foreground'
                            "
                            >{{
                                formatNaira(
                                    (counts[String(n)] || 0) * n * 100,
                                    {
                                        trim: true,
                                    },
                                )
                            }}</span
                        >
                    </li>
                    <li class="flex items-center gap-3 px-5 py-2">
                        <span
                            class="flex w-16 items-center gap-1.5 font-semibold"
                            ><Coins class="size-4 text-muted-foreground" />
                            Coins</span
                        >
                        <Input
                            v-model="coinsText"
                            inputmode="decimal"
                            placeholder="₦0"
                            class="tabular h-9 w-36"
                            aria-label="Coins and loose change, in naira"
                        />
                    </li>
                </ul>
                <div
                    class="flex items-baseline justify-between border-t bg-muted/40 px-5 py-3"
                >
                    <span class="text-sm font-medium">Counted</span>
                    <span class="tabular text-2xl font-bold"
                        ><Amount :kobo="counted"
                    /></span>
                </div>
            </section>

            <!-- Wallets -->
            <section
                v-if="walletsToCount.length"
                class="rounded-2xl border bg-card"
            >
                <h2
                    class="flex items-center gap-2 border-b px-5 py-3 font-semibold"
                >
                    <Wallet class="size-4 text-muted-foreground" /> Wallet
                    balances
                </h2>
                <p class="px-5 pt-3 text-sm text-muted-foreground">
                    Type the balance shown on each terminal right now.
                </p>
                <ul class="grid gap-3 p-5 pt-3 sm:grid-cols-2">
                    <li
                        v-for="w in walletsToCount"
                        :key="w.id"
                        class="grid gap-1.5"
                    >
                        <Label
                            :for="`wallet-${w.id}`"
                            class="flex items-center gap-2"
                        >
                            <ProviderDot :color="w.color" />
                            {{ w.label }}
                        </Label>
                        <Input
                            :id="`wallet-${w.id}`"
                            v-model="walletText[w.id]"
                            inputmode="decimal"
                            placeholder="₦0"
                            class="tabular h-10"
                        />
                        <p
                            v-if="w.terminals.length > 1"
                            class="text-xs text-muted-foreground"
                        >
                            {{ w.terminals.join(' and ') }} share this balance
                        </p>
                        <InputError :message="errors[`wallets.${w.id}`]" />
                    </li>
                </ul>
            </section>

            <div class="grid gap-2">
                <Label for="count-note"
                    >Anything to explain? (needed if it doesn't match)</Label
                >
                <textarea
                    id="count-note"
                    name="note"
                    rows="2"
                    maxlength="300"
                    class="rounded-md border bg-transparent px-3 py-2 text-sm"
                    placeholder="e.g. Gave a customer ₦500 change twice"
                />
                <InputError :message="errors.note ?? errors.date" />
            </div>

            <div
                class="sticky bottom-0 -mx-4 border-t bg-background/95 px-4 py-3 backdrop-blur md:static md:mx-0 md:border-0 md:bg-transparent md:p-0"
            >
                <Button
                    type="submit"
                    class="h-12 w-full bg-gold text-base font-bold text-gold-foreground hover:bg-gold/90"
                    :disabled="processing || !walletsFilled"
                >
                    <Spinner v-if="processing" /><Lock v-else class="size-4" />
                    Submit count ·
                    <Amount :kobo="counted" hide-kobo />
                </Button>
                <p class="mt-2 text-center text-xs text-muted-foreground">
                    Submitting closes
                    {{ isToday ? "today's" : 'this day’s' }} books for
                    {{ outlet.name }}.
                </p>
            </div>
        </Form>

        <div
            v-else-if="counting"
            class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground"
        >
            Not counted yet. An agent or supervisor at {{ outlet.name }} counts
            the day.
        </div>

        <!-- ================= RESULT ================= -->
        <template v-if="rec && !counting">
            <section
                class="flex items-center gap-3 rounded-2xl border px-5 py-4"
                :class="
                    rec.balances
                        ? 'border-gain/30 bg-gain-soft'
                        : 'border-warning/30 bg-warning-soft'
                "
            >
                <component
                    :is="rec.balances ? CircleCheck : CircleAlert"
                    class="size-6 shrink-0"
                    :class="rec.balances ? 'text-gain' : 'text-warning'"
                />
                <div>
                    <p class="font-semibold">
                        {{
                            rec.balances
                                ? 'Everything matches the books.'
                                : rec.cash_variance < 0
                                  ? `The drawer is ${formatNaira(-rec.cash_variance, { trim: true })} short.`
                                  : rec.cash_variance > 0
                                    ? `The drawer has ${formatNaira(rec.cash_variance, { trim: true })} more than expected.`
                                    : 'A wallet does not match the books.'
                        }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Counted by {{ rec.submitted_by }} ·
                        {{ format('dayTime', rec.submitted_at) }}
                        <template
                            v-if="rec.decided_by && rec.status === 'approved'"
                        >
                            · Approved by {{ rec.decided_by }}</template
                        >
                    </p>
                </div>
            </section>

            <section class="rounded-2xl border bg-card">
                <div
                    class="hidden gap-4 border-b px-5 py-2.5 text-xs font-medium text-muted-foreground sm:flex"
                    aria-hidden="true"
                >
                    <span class="flex-1"></span>
                    <span class="w-28 text-right">Books</span>
                    <span class="w-28 text-right">Counted</span>
                    <span class="w-28 text-right">Difference</span>
                </div>
                <ul class="tabular divide-y text-sm">
                    <li
                        v-for="row in rows"
                        :key="row.key"
                        class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3"
                    >
                        <span
                            class="flex min-w-0 flex-1 items-center gap-2 font-medium"
                        >
                            <Banknote
                                v-if="row.key === 'cash'"
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <ProviderDot v-else :color="row.color" />
                            <span class="truncate">{{ row.label }}</span>
                        </span>
                        <span class="hidden w-28 text-right sm:block"
                            ><Amount :kobo="row.expected"
                        /></span>
                        <span
                            class="hidden w-28 text-right font-semibold sm:block"
                            ><Amount :kobo="row.actual"
                        /></span>
                        <span class="text-right sm:w-28">
                            <StatusPill :tone="tone(row.variance)">
                                <Amount :kobo="row.variance" signed />
                            </StatusPill>
                        </span>
                        <span
                            class="w-full text-xs text-muted-foreground sm:hidden"
                        >
                            Books <Amount :kobo="row.expected" hide-kobo /> ·
                            Counted <Amount :kobo="row.actual" hide-kobo />
                        </span>
                    </li>
                </ul>
                <details class="border-t px-5 py-3 text-sm">
                    <summary class="cursor-pointer text-muted-foreground">
                        Notes counted
                    </summary>
                    <ul
                        class="tabular mt-2 grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-3"
                    >
                        <template v-for="n in notes" :key="n">
                            <li v-if="rec.denominations[String(n)]">
                                ₦{{ n.toLocaleString('en-NG') }} ×
                                {{ rec.denominations[String(n)] }}
                            </li>
                        </template>
                        <li v-if="rec.denominations.coins">
                            Coins
                            <Amount :kobo="rec.denominations.coins" />
                        </li>
                    </ul>
                </details>
            </section>

            <p
                v-if="rec.note"
                class="rounded-2xl border bg-card px-5 py-3 text-sm"
            >
                <span class="text-muted-foreground"
                    >{{ rec.submitted_by }} said:</span
                >
                {{ rec.note }}
            </p>
            <p
                v-if="rec.decision_note && rec.status === 'approved'"
                class="rounded-2xl border bg-card px-5 py-3 text-sm"
            >
                <span class="text-muted-foreground"
                    >{{ rec.decided_by }} said:</span
                >
                {{ rec.decision_note }}
            </p>

            <!-- Staff shortages -->
            <section
                v-if="rec.shortages.length"
                class="rounded-2xl border bg-card"
            >
                <h2 class="border-b px-5 py-3 font-semibold">
                    Who covers the shortage
                </h2>
                <ul class="divide-y">
                    <li
                        v-for="s in rec.shortages"
                        :key="s.id"
                        class="flex items-center gap-3 px-5 py-3 text-sm"
                    >
                        <span class="flex-1">{{ s.person }}</span>
                        <span class="tabular font-semibold"
                            ><Amount :kobo="s.amount"
                        /></span>
                        <StatusPill
                            :tone="
                                s.status === 'recovered'
                                    ? 'gain'
                                    : s.status === 'written_off'
                                      ? 'neutral'
                                      : 'warning'
                            "
                            >{{
                                {
                                    outstanding: 'Owes',
                                    recovered: 'Paid back',
                                    written_off: 'Written off',
                                }[s.status] ?? s.status
                            }}</StatusPill
                        >
                        <template
                            v-if="s.status === 'outstanding' && canSettle"
                        >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="settle(s.id, 'recovered')"
                                >Paid back</Button
                            >
                        </template>
                    </li>
                </ul>
            </section>

            <!-- Decide -->
            <Form
                v-if="canDecide"
                v-bind="ReconciliationController.approve.form(rec.id)"
                :transform="approvePayload"
                :options="{ preserveScroll: true }"
                class="grid gap-4 rounded-2xl border bg-card p-5"
                #default="{ errors, processing }"
            >
                <h2 class="font-semibold">Approve this count</h2>
                <p class="-mt-2 text-sm text-muted-foreground">
                    {{
                        rec.balances
                            ? 'Approving closes the day for good.'
                            : 'Approving posts the difference to "Cash shortages and excess" so the books match what is in the shop.'
                    }}
                </p>

                <fieldset
                    v-if="shortfall > 0 && staff.length"
                    class="grid gap-2"
                >
                    <legend class="mb-1 text-sm font-medium">
                        Hold someone responsible? (optional)
                    </legend>
                    <div
                        v-for="p in staff"
                        :key="p.id"
                        class="flex items-center gap-3"
                    >
                        <Label :for="`assign-${p.id}`" class="flex-1">
                            {{ p.name }}
                            <span class="font-normal text-muted-foreground"
                                >· {{ p.role }}</span
                            >
                        </Label>
                        <Input
                            :id="`assign-${p.id}`"
                            v-model="assign[p.id]"
                            inputmode="decimal"
                            placeholder="₦0"
                            class="tabular h-9 w-32"
                        />
                    </div>
                    <p
                        class="text-xs"
                        :class="
                            assigned > shortfall
                                ? 'text-loss'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ formatNaira(assigned, { trim: true }) }} of
                        {{ formatNaira(shortfall, { trim: true }) }} assigned.
                        The rest is the business's loss.
                    </p>
                    <InputError :message="errors.shortages" />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="approve-note">Note (optional)</Label>
                    <Input
                        id="approve-note"
                        name="note"
                        maxlength="300"
                        placeholder="e.g. Agent will pay back on Friday"
                    />
                    <InputError :message="errors.note" />
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        type="submit"
                        :disabled="processing || assigned > shortfall"
                    >
                        <Spinner v-if="processing" /><CircleCheck
                            v-else
                            class="size-4"
                        />
                        Approve and close the day
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="sendBackOpen = true"
                    >
                        <RotateCcw class="size-4" /> Send back for a recount
                    </Button>
                </div>
            </Form>
            <div
                v-else-if="canSendBack"
                class="flex flex-wrap items-center gap-3 rounded-2xl border bg-card p-5 text-sm"
            >
                <p class="flex-1 text-muted-foreground">
                    Someone else has to approve your own count.
                </p>
                <Button variant="outline" @click="sendBackOpen = true">
                    <RotateCcw class="size-4" /> Take it back and recount
                </Button>
            </div>

            <div v-if="canReopen" class="flex justify-end">
                <Button
                    variant="ghost"
                    class="text-muted-foreground"
                    @click="reopenOpen = true"
                >
                    <RotateCcw class="size-4" /> Reopen this day
                </Button>
            </div>
        </template>
    </div>

    <Dialog v-if="rec" v-model:open="sendBackOpen">
        <DialogContent class="sm:max-w-md">
            <Form
                v-bind="ReconciliationController.sendBack.form(rec.id)"
                class="grid gap-4"
                @success="sendBackOpen = false"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Send back for a recount</DialogTitle>
                    <DialogDescription>
                        The day opens again so it can be recounted. Say what to
                        check.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="send-back-note">What to check</Label>
                    <Input
                        id="send-back-note"
                        name="note"
                        placeholder="e.g. Count the ₦1,000 notes again"
                        required
                    />
                    <InputError :message="errors.note" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" /> Send back
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <Dialog v-if="rec" v-model:open="reopenOpen">
        <DialogContent class="sm:max-w-md">
            <Form
                v-bind="ReconciliationController.reopen.form(rec.id)"
                class="grid gap-4"
                @success="reopenOpen = false"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Reopen this day?</DialogTitle>
                    <DialogDescription>
                        The difference posted when it was approved is undone,
                        any unpaid staff shortages from it are cancelled, and
                        the day can be counted again. This is recorded in the
                        audit log.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="reopen-reason">Reason</Label>
                    <Input
                        id="reopen-reason"
                        name="reason"
                        placeholder="e.g. Found ₦2,000 in the safe"
                        required
                    />
                    <InputError :message="errors.reason" />
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" /> Reopen day
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
