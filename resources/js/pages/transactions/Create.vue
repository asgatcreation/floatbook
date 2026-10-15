<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Banknote,
    ChevronDown,
    Delete,
    Wallet,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Amount from '@/components/Amount.vue';
import InputError from '@/components/InputError.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useSync } from '@/composables/useSync';
import { effects, quote } from '@/lib/charges';
import type { Rule } from '@/lib/charges';
import { formatNaira } from '@/lib/money';
import { create, index } from '@/routes/transactions';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
type Terminal = {
    id: string;
    name: string;
    label: string;
    code: string | null;
    provider_id: string;
    provider: { name: string; color: string };
    wallet_id: string;
    wallet_label: string;
};
type Outlet = {
    id: string;
    name: string;
    cash: number;
    terminals: Terminal[];
    /** Balance per wallet; terminals on the same account share one. */
    wallets: Record<string, number>;
};
type TypeOption = {
    value: string;
    label: string;
    short: string;
    flow: 'cash_out' | 'cash_in' | 'service';
    can_pay_cash_while_pending: boolean;
};
type Recent = {
    id: string;
    type_label: string;
    amount: number;
    status: string;
    voided: boolean;
    provider: { name: string; color: string } | null;
    terminal: string | null;
    occurred_at: string;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Transactions', href: index() },
            { title: 'New', href: create() },
        ],
    },
});

const props = defineProps<{
    outlets: Outlet[];
    lastTerminalId: string | null;
    types: TypeOption[];
    rules: Rule[];
    recent: Recent[];
}>();

const page = usePage();
const saveErrors = ref<Record<string, string>>({});
const errors = computed(() => ({
    ...(page.props.errors as Record<string, string>),
    ...saveErrors.value,
}));

// ---- Form state -------------------------------------------------------------
// Start where this person left off: the outlet and terminal they used last.
const startOutlet =
    props.outlets.find((o) =>
        o.terminals.some((t) => t.id === props.lastTerminalId),
    ) ?? props.outlets[0];
const outletId = ref(startOutlet?.id ?? '');
const terminalId = ref(
    startOutlet?.terminals.find((t) => t.id === props.lastTerminalId)?.id ??
        startOutlet?.terminals[0]?.id ??
        '',
);
const type = ref(props.types[0]?.value ?? 'cash_withdrawal');
const digits = ref('');
const status = ref<'successful' | 'pending' | 'failed'>('successful');
const cashPaid = ref(false);
const showDetails = ref(false);
const customerPhone = ref('');
const customerName = ref('');
const reference = ref('');
const note = ref('');
const saving = ref(false);
const idempotencyKey = ref(crypto.randomUUID());

const outlet = computed(() =>
    props.outlets.find((o) => o.id === outletId.value),
);
const terminal = computed(() =>
    outlet.value?.terminals.find((t) => t.id === terminalId.value),
);
const providerId = computed(() => terminal.value?.provider_id ?? '');

// Switching outlet picks that outlet's first terminal.
watch(outletId, () => {
    if (!terminal.value) {
        terminalId.value = outlet.value?.terminals[0]?.id ?? '';
    }
});
const typeOption = computed(() =>
    props.types.find((t) => t.value === type.value),
);
const amount = computed(() => Number(digits.value || '0') * 100);

const preview = computed(() =>
    quote(
        props.rules,
        type.value,
        typeOption.value?.flow === 'service',
        providerId.value,
        outletId.value,
        amount.value,
    ),
);
const change = computed(() =>
    effects(typeOption.value?.flow ?? 'cash_in', amount.value, preview.value),
);
const movesMoney = computed(() => status.value === 'successful');

// Entries saved on this phone but not yet on the server. They live in the phone's
// queue, so the adjusted balances survive a reload while still offline.
const sync = useSync();
const pendingEffects = computed(() =>
    sync.waiting.value
        .map((entry) => entry.effect)
        .filter((effect) => effect?.outlet_id === outletId.value),
);

// Balances as the server knows them, plus anything saved offline on this phone.
const cashNow = computed(
    () =>
        (outlet.value?.cash ?? 0) +
        pendingEffects.value.reduce((sum, e) => sum + (e?.cash ?? 0), 0),
);
const cashAfter = computed(
    () => cashNow.value + (movesMoney.value ? change.value.cash : 0),
);
const walletBefore = computed(
    () =>
        (outlet.value?.wallets[terminal.value?.wallet_id ?? ''] ?? 0) +
        pendingEffects.value
            .filter((e) => e?.wallet_id === terminal.value?.wallet_id)
            .reduce((sum, e) => sum + (e?.wallet ?? 0), 0),
);
const walletAfter = computed(
    () => walletBefore.value + (movesMoney.value ? change.value.wallet : 0),
);
const cashGoesNegative = computed(
    () => movesMoney.value && amount.value > 0 && cashAfter.value < 0,
);
const walletGoesNegative = computed(
    () => movesMoney.value && amount.value > 0 && walletAfter.value < 0,
);

watch(type, () => {
    if (!typeOption.value?.can_pay_cash_while_pending) {
        cashPaid.value = false;
    }
});

const amountLabel = computed(() =>
    typeOption.value?.flow === 'service' ? 'Price the customer pays' : 'Amount',
);

const saveLabel = computed(() => {
    if (!amount.value) {
        return 'Enter an amount';
    }

    const verb =
        status.value === 'successful' ? 'Save' : `Save as ${status.value}`;

    return `${verb} · ${formatNaira(amount.value, { trim: true })}`;
});

// ---- Keypad -----------------------------------------------------------------
const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '00', '0', 'del'];

function press(key: string): void {
    if (key === 'del') {
        digits.value = digits.value.slice(0, -1);

        return;
    }

    const next = (digits.value + key).replace(/^0+/, '');

    if (next.length <= 8) {
        digits.value = next;
    }
}

function isTyping(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
            target.isContentEditable)
    );
}

function onKeydown(event: KeyboardEvent): void {
    if (
        isTyping(event.target) ||
        event.ctrlKey ||
        event.metaKey ||
        event.altKey
    ) {
        return;
    }

    if (/^\d$/.test(event.key)) {
        event.preventDefault();
        press(event.key);
    } else if (event.key === 'Backspace') {
        event.preventDefault();
        press('del');
    } else if (event.key === 'Enter') {
        event.preventDefault();
        save();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));

// ---- Save -------------------------------------------------------------------
// Every entry is saved on the phone first, then sent. With a connection the agent sees
// no difference; without one it waits in the queue and syncs when the network returns.
function resetForNextCustomer(): void {
    // A new key means the next save is a new transaction.
    digits.value = '';
    status.value = 'successful';
    cashPaid.value = false;
    customerPhone.value = '';
    customerName.value = '';
    reference.value = '';
    note.value = '';
    showDetails.value = false;
    saveErrors.value = {};
    idempotencyKey.value = crypto.randomUUID();
}

async function save(): Promise<void> {
    if (!amount.value || saving.value) {
        return;
    }

    saving.value = true;
    const summary = `${formatNaira(amount.value, { trim: true })} ${typeOption.value?.label.toLowerCase() ?? ''}`;

    try {
        const result = await sync.save(
            {
                idempotency_key: idempotencyKey.value,
                terminal_id: terminalId.value,
                type: type.value,
                amount: amount.value,
                status: status.value,
                cash_paid_while_pending: cashPaid.value,
                customer_phone: customerPhone.value || null,
                customer_name: customerName.value || null,
                reference: reference.value || null,
                note: note.value || null,
                client_recorded_at: new Date().toISOString(),
            },
            summary,
            movesMoney.value
                ? {
                      outlet_id: outletId.value,
                      provider_id: providerId.value,
                      wallet_id: terminal.value?.wallet_id ?? '',
                      cash: change.value.cash,
                      wallet: change.value.wallet,
                  }
                : null,
        );

        if (result.ok === 'queued') {
            toast.success(
                `Saved on this phone: ${summary}. It will sync when you're back online.`,
            );
            resetForNextCustomer();
        } else if (result.ok) {
            toast.success(
                result.duplicate
                    ? 'Already saved — this entry was not recorded twice.'
                    : `Saved ${summary}${status.value === 'successful' ? '' : ` as ${status.value}`}.`,
            );
            resetForNextCustomer();
            router.reload({ only: ['outlets', 'recent'] });
        } else {
            saveErrors.value = result.errors ?? { amount: result.message };
            toast.error(result.message);
        }
    } finally {
        saving.value = false;
    }
}

// When queued entries reach the server, show the real balances again.
onMounted(() => {
    const stop = sync.onSynced(() => {
        router.reload({ only: ['outlets', 'recent'] });
    });
    onUnmounted(stop);
});

const time = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
});

const statusTone = (s: string, voided: boolean) =>
    voided
        ? 'neutral'
        : s === 'successful'
          ? 'gain'
          : s === 'pending'
            ? 'pending'
            : 'loss';
</script>

<template>
    <Head title="New transaction" />

    <div
        v-if="outlets.length === 0"
        class="m-4 rounded-2xl border border-dashed bg-card p-8 text-center md:m-6"
    >
        <h1 class="text-lg font-semibold">No outlet to record for</h1>
        <p class="mt-1 text-sm text-muted-foreground">
            Ask the owner to add an outlet and assign you to it.
        </p>
    </div>

    <div
        v-else
        class="mx-auto grid w-full max-w-6xl gap-6 p-3 sm:p-4 md:p-6 lg:grid-cols-[minmax(0,1fr)_360px]"
    >
        <!-- Entry -->
        <section
            aria-label="New transaction"
            class="flex min-w-0 flex-col gap-3"
        >
            <!-- Where and how much float -->
            <div class="flex flex-wrap items-center gap-2">
                <label v-if="outlets.length > 1" class="sr-only" for="outlet"
                    >Outlet</label
                >
                <select
                    v-if="outlets.length > 1"
                    id="outlet"
                    v-model="outletId"
                    class="h-10 rounded-xl border bg-card px-3 text-sm font-semibold"
                >
                    <option v-for="o in outlets" :key="o.id" :value="o.id">
                        {{ o.name }}
                    </option>
                </select>
                <p v-else class="text-sm font-semibold">{{ outlet?.name }}</p>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-2xl border bg-card px-3.5 py-2.5">
                    <p
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Banknote class="size-3.5" /> Cash in drawer
                    </p>
                    <p
                        class="text-lg font-bold"
                        :class="{ 'text-loss': cashNow < 0 }"
                    >
                        <Amount :kobo="cashNow" hide-kobo />
                    </p>
                </div>
                <div class="rounded-2xl border bg-card px-3.5 py-2.5">
                    <p
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Wallet class="size-3.5" />
                        <span class="truncate">{{
                            terminal?.wallet_label
                        }}</span>
                    </p>
                    <p
                        class="text-lg font-bold"
                        :class="{ 'text-loss': walletBefore < 0 }"
                    >
                        <Amount :kobo="walletBefore" hide-kobo />
                    </p>
                </div>
            </div>

            <!-- What -->
            <fieldset>
                <legend class="sr-only">Transaction type</legend>
                <div class="grid grid-cols-4 gap-1.5">
                    <label
                        v-for="t in types"
                        :key="t.value"
                        class="flex min-h-12 cursor-pointer items-center justify-center rounded-xl border bg-card px-1 text-center text-[13px] leading-tight font-semibold transition select-none has-[:checked]:border-primary has-[:checked]:bg-primary has-[:checked]:text-primary-foreground has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                    >
                        <input
                            v-model="type"
                            type="radio"
                            name="type"
                            :value="t.value"
                            :aria-label="t.label"
                            class="sr-only"
                        />
                        {{ t.short }}
                    </label>
                </div>
            </fieldset>

            <fieldset>
                <legend
                    class="mb-1.5 text-xs font-medium text-muted-foreground"
                >
                    Terminal
                </legend>
                <div
                    class="grid gap-1.5"
                    :class="
                        (outlet?.terminals.length ?? 0) > 2
                            ? 'grid-cols-2 sm:grid-cols-3'
                            : 'grid-cols-2'
                    "
                >
                    <label
                        v-for="t in outlet?.terminals ?? []"
                        :key="t.id"
                        class="flex min-h-12 min-w-0 cursor-pointer items-center gap-2 rounded-xl border bg-card px-3 py-1.5 text-sm transition select-none has-[:checked]:border-primary has-[:checked]:ring-1 has-[:checked]:ring-primary has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        :data-test="`terminal-${t.name}`"
                    >
                        <input
                            v-model="terminalId"
                            type="radio"
                            name="terminal"
                            :value="t.id"
                            :aria-label="t.label"
                            class="sr-only"
                        />
                        <ProviderDot :color="t.provider.color" />
                        <span class="min-w-0 leading-tight">
                            <span class="block truncate font-semibold">{{
                                t.name
                            }}</span>
                            <span
                                v-if="t.label !== t.name || t.code"
                                class="block truncate text-xs text-muted-foreground"
                                >{{
                                    [
                                        t.label !== t.name
                                            ? t.provider.name
                                            : '',
                                        t.code,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}</span
                            >
                        </span>
                    </label>
                </div>
                <InputError
                    :message="errors.terminal_id ?? errors.provider_id"
                />
            </fieldset>

            <!-- How much -->
            <div class="rounded-2xl border bg-card px-4 pt-3 pb-3.5">
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-xs text-muted-foreground">{{
                        amountLabel
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        typeOption?.label
                    }}</span>
                </div>
                <output
                    class="tabular block truncate text-[2.5rem] leading-tight font-bold tracking-tight"
                    :class="{ 'text-muted-foreground/50': !amount }"
                    aria-live="polite"
                    >{{ formatNaira(amount, { trim: true }) }}</output
                >
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                    <span v-if="typeOption?.flow !== 'service'"
                        >Charge
                        <strong><Amount :kobo="preview.charge" /></strong
                    ></span>
                    <span v-if="preview.providerFee"
                        >Fee
                        <strong><Amount :kobo="preview.providerFee" /></strong
                    ></span>
                    <span v-if="preview.commission"
                        >Commission
                        <strong><Amount :kobo="preview.commission" /></strong
                    ></span>
                    <span
                        class="font-semibold"
                        :class="preview.profit >= 0 ? 'text-gain' : 'text-loss'"
                        >Profit <Amount :kobo="preview.profit" signed
                    /></span>
                </div>
                <InputError :message="errors.amount" />
            </div>

            <div
                class="grid grid-cols-3 gap-1.5"
                role="group"
                aria-label="Keypad"
            >
                <Button
                    v-for="key in keys"
                    :key="key"
                    type="button"
                    variant="outline"
                    class="h-[3.25rem] rounded-xl bg-card text-xl font-semibold shadow-none active:scale-[0.98]"
                    :aria-label="key === 'del' ? 'Delete digit' : key"
                    @click="press(key)"
                >
                    <Delete v-if="key === 'del'" class="size-5" />
                    <template v-else>{{ key }}</template>
                </Button>
            </div>

            <!-- Outcome -->
            <fieldset>
                <legend class="sr-only">Status</legend>
                <div class="grid grid-cols-3 gap-1.5">
                    <label
                        v-for="s in [
                            'successful',
                            'pending',
                            'failed',
                        ] as const"
                        :key="s"
                        class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border bg-card text-sm font-semibold capitalize transition select-none has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        :class="
                            status === s
                                ? s === 'successful'
                                    ? 'border-gain text-gain ring-1 ring-gain'
                                    : s === 'pending'
                                      ? 'border-pending text-pending ring-1 ring-pending'
                                      : 'border-loss text-loss ring-1 ring-loss'
                                : 'text-muted-foreground'
                        "
                    >
                        <input
                            v-model="status"
                            type="radio"
                            name="status"
                            :value="s"
                            :aria-label="s"
                            class="sr-only"
                        />
                        {{ s }}
                    </label>
                </div>
            </fieldset>

            <label
                v-if="
                    status === 'pending' &&
                    typeOption?.can_pay_cash_while_pending
                "
                class="flex items-start gap-3 rounded-xl border border-pending/40 bg-pending-soft p-3 text-sm"
            >
                <input
                    v-model="cashPaid"
                    type="checkbox"
                    class="mt-0.5 size-4 accent-[var(--pending)]"
                />
                <span>
                    <span class="font-semibold"
                        >I already gave the customer cash</span
                    >
                    <span class="block text-muted-foreground"
                        >We'll track it until the provider confirms, so the
                        drawer still adds up.</span
                    >
                </span>
            </label>
            <p
                v-else-if="status !== 'successful'"
                class="rounded-xl bg-muted px-3 py-2 text-sm text-muted-foreground"
            >
                {{
                    status === 'pending'
                        ? "Nothing moves until it's confirmed. You'll find it under Pending."
                        : 'A failed transaction is recorded for history, but moves no money.'
                }}
            </p>

            <!-- Optional details -->
            <div class="rounded-2xl border bg-card">
                <button
                    type="button"
                    class="flex min-h-11 w-full items-center justify-between px-4 text-sm font-semibold"
                    :aria-expanded="showDetails"
                    @click="showDetails = !showDetails"
                >
                    Customer & reference
                    <span
                        class="flex items-center gap-2 font-normal text-muted-foreground"
                    >
                        {{ reference || customerPhone ? 'Added' : 'Optional' }}
                        <ChevronDown
                            class="size-4 transition"
                            :class="{ 'rotate-180': showDetails }"
                        />
                    </span>
                </button>
                <div
                    v-if="showDetails"
                    class="grid gap-3 border-t p-4 sm:grid-cols-2"
                >
                    <div class="grid gap-1.5">
                        <Label for="reference">Reference / RRN</Label>
                        <Input
                            id="reference"
                            v-model="reference"
                            autocomplete="off"
                            inputmode="numeric"
                        />
                        <InputError :message="errors.reference" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="customer_phone">Customer phone</Label>
                        <Input
                            id="customer_phone"
                            v-model="customerPhone"
                            type="tel"
                            autocomplete="off"
                        />
                        <InputError :message="errors.customer_phone" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="customer_name">Customer name</Label>
                        <Input
                            id="customer_name"
                            v-model="customerName"
                            autocomplete="off"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="note">Note</Label>
                        <Input id="note" v-model="note" autocomplete="off" />
                    </div>
                </div>
            </div>

            <InputError
                :message="errors.outlet_id ?? errors.type ?? errors.status"
            />

            <!-- On phones the save button stays in reach, in a solid bar at the bottom of the screen. -->
            <div
                class="sticky bottom-0 z-10 -mx-3 border-t bg-background/95 px-3 pt-2.5 pb-3 backdrop-blur sm:-mx-4 sm:px-4 lg:static lg:mx-0 lg:border-0 lg:bg-transparent lg:p-0 lg:backdrop-blur-none"
            >
                <Button
                    type="button"
                    class="h-14 w-full rounded-2xl text-base font-bold disabled:bg-muted disabled:text-muted-foreground disabled:opacity-100"
                    :disabled="!amount || saving"
                    data-test="save-transaction"
                    @click="save"
                >
                    <Spinner v-if="saving" />
                    {{ saveLabel }}
                </Button>
            </div>
            <p
                class="hidden text-center text-xs text-muted-foreground lg:block"
            >
                Type numbers on your keyboard · Backspace to delete · Enter to
                save
            </p>
        </section>

        <!-- What happens + recent -->
        <aside class="flex flex-col gap-4">
            <section
                aria-label="What this does to your float"
                class="rounded-2xl border bg-card p-4"
            >
                <h2 class="text-sm font-semibold">What this does</h2>
                <dl class="mt-3 grid gap-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <dt
                            class="flex items-center gap-2 text-muted-foreground"
                        >
                            <Banknote class="size-4" /> Cash in drawer
                        </dt>
                        <dd class="text-right font-semibold">
                            <Amount
                                v-if="movesMoney && amount"
                                :kobo="change.cash"
                                tone="auto"
                                signed
                            />
                            <span v-else class="text-muted-foreground"
                                >No change</span
                            >
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt
                            class="flex items-center gap-2 text-muted-foreground"
                        >
                            <Wallet class="size-4" />
                            {{ terminal?.wallet_label }}
                        </dt>
                        <dd class="text-right font-semibold">
                            <Amount
                                v-if="movesMoney && amount"
                                :kobo="change.wallet"
                                tone="auto"
                                signed
                            />
                            <span v-else class="text-muted-foreground"
                                >No change</span
                            >
                        </dd>
                    </div>
                    <div class="border-t pt-3">
                        <div class="flex items-center justify-between">
                            <dt class="text-muted-foreground">Drawer after</dt>
                            <dd class="font-semibold">
                                <Amount :kobo="cashAfter" />
                            </dd>
                        </div>
                        <div class="mt-1 flex items-center justify-between">
                            <dt class="text-muted-foreground">Wallet after</dt>
                            <dd class="font-semibold">
                                <Amount :kobo="walletAfter" />
                            </dd>
                        </div>
                    </div>
                </dl>
                <p
                    v-if="cashGoesNegative || walletGoesNegative"
                    role="alert"
                    class="mt-3 rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning"
                >
                    {{ cashGoesNegative ? 'The drawer' : 'The wallet' }} would
                    go below zero. Check the amount, or ask for a top-up first.
                </p>
            </section>

            <section
                aria-label="Your recent entries"
                class="rounded-2xl border bg-card"
            >
                <h2 class="px-4 pt-4 text-sm font-semibold">
                    Your recent entries
                </h2>
                <p
                    v-if="recent.length === 0"
                    class="px-4 pt-1 pb-4 text-sm text-muted-foreground"
                >
                    Nothing yet today. Your entries will show here.
                </p>
                <ul v-else class="mt-2 divide-y">
                    <li
                        v-for="r in recent"
                        :key="r.id"
                        class="flex items-center gap-3 px-4 py-2.5"
                    >
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"
                            aria-hidden="true"
                        >
                            <ArrowUpRight
                                v-if="r.type_label === 'Withdrawal'"
                                class="size-4"
                            />
                            <ArrowDownLeft v-else class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p
                                class="truncate text-sm font-medium"
                                :class="{ 'line-through opacity-60': r.voided }"
                            >
                                {{ r.type_label }}
                            </p>
                            <p
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <ProviderDot :color="r.provider?.color" />
                                {{ r.terminal ?? r.provider?.name }} ·
                                {{ time.format(new Date(r.occurred_at)) }}
                            </p>
                        </div>
                        <div class="flex flex-col items-end gap-0.5">
                            <span class="text-sm font-semibold"
                                ><Amount :kobo="r.amount" hide-kobo
                            /></span>
                            <StatusPill
                                v-if="r.voided || r.status !== 'successful'"
                                :tone="statusTone(r.status, r.voided)"
                            >
                                {{ r.voided ? 'Voided' : r.status }}
                            </StatusPill>
                        </div>
                    </li>
                </ul>
            </section>
        </aside>
    </div>
</template>
