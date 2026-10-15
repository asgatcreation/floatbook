<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Banknote,
    Bell,
    Building2,
    Check,
    Clock,
    Landmark,
    Plus,
    Store,
    Wallet,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import FloatController from '@/actions/App/Http/Controllers/FloatController';
import Amount from '@/components/Amount.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { useTenant } from '@/composables/useTenant';
import { koboToInput, parseNaira } from '@/lib/money';
import { index } from '@/routes/float';
import { index as outletsIndex } from '@/routes/outlets';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
type Account = {
    id: string;
    label: string;
    color: string | null;
    balance: number;
    low_balance_threshold: number | null;
    is_low: boolean;
};
type OutletFloat = {
    id: string;
    name: string;
    cash: Account;
    wallets: Account[];
    pending_payouts: number;
};
type Movement = {
    id: string;
    type: string;
    type_label: string;
    amount: number;
    from: string | null;
    to: string;
    status: 'requested' | 'completed' | 'rejected' | 'cancelled';
    reason: string | null;
    requested_by: string | null;
    decided_by: string | null;
    decision_note: string | null;
    created_at: string;
    can_approve: boolean;
    can_cancel: boolean;
};
type Option = {
    id: string;
    label: string;
    kind: 'cash' | 'wallet' | 'head_office';
    outlet_id: string | null;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Float', href: index() }] },
});

const props = defineProps<{
    outlets: OutletFloat[];
    headOffice: Account | null;
    movements: Movement[];
    accountOptions: Option[];
    types: { value: string; label: string }[];
}>();

const { tenant, can } = useTenant();
const isOwner = computed(() => tenant.value?.membership.role === 'owner');

const totalFloat = (o: OutletFloat) =>
    o.cash.balance + o.wallets.reduce((sum, w) => sum + w.balance, 0);
const awaiting = computed(() =>
    props.movements.filter((m) => m.status === 'requested'),
);
const history = computed(() =>
    props.movements.filter((m) => m.status !== 'requested').slice(0, 15),
);

// ---- Move money dialog --------------------------------------------------------
const descriptions: Record<string, string> = {
    opening_balance:
        'Money already at an outlet or head office when you started using FloatBook.',
    top_up: 'Send money from head office to an outlet’s cash or wallet.',
    outlet_transfer: 'Move cash or wallet balance from one outlet to another.',
    cash_pickup: 'Collect cash from an outlet back to head office or the bank.',
    rebalance:
        'Swap between cash and wallet at the same outlet, e.g. deposit cash to fund the wallet.',
};

const moveOpen = ref(false);
const moveType = ref('top_up');
const fromId = ref('');
const toId = ref('');
const amountText = ref('');
const reason = ref('');
const moving = ref(false);
const errors = ref<Record<string, string>>({});

const isFloat = (o: Option) => o.kind === 'cash' || o.kind === 'wallet';
const fromOptions = computed(() => {
    switch (moveType.value) {
        case 'top_up':
            return props.accountOptions.filter((o) => o.kind === 'head_office');
        case 'cash_pickup':
            return props.accountOptions.filter((o) => o.kind === 'cash');
        case 'opening_balance':
            return [];
        default:
            return props.accountOptions.filter(isFloat);
    }
});
const from = computed(() =>
    props.accountOptions.find((o) => o.id === fromId.value),
);
const toOptions = computed(() => {
    switch (moveType.value) {
        case 'opening_balance':
            return props.accountOptions;
        case 'top_up':
            return props.accountOptions.filter(isFloat);
        case 'cash_pickup':
            return props.accountOptions.filter((o) => o.kind === 'head_office');
        case 'outlet_transfer':
            return props.accountOptions.filter(
                (o) => isFloat(o) && o.outlet_id !== from.value?.outlet_id,
            );
        default:
            return props.accountOptions.filter(
                (o) =>
                    isFloat(o) &&
                    o.outlet_id === from.value?.outlet_id &&
                    o.id !== fromId.value,
            );
    }
});

// Keep choices valid when the kind of move changes (but keep a preset choice if it still fits).
watch(moveType, () => {
    if (!fromOptions.value.some((o) => o.id === fromId.value)) {
        fromId.value = fromOptions.value[0]?.id ?? '';
    }
});
watch([fromId, moveType], () => {
    if (!toOptions.value.some((o) => o.id === toId.value)) {
        toId.value = toOptions.value[0]?.id ?? '';
    }
});

function openMove(
    type = 'top_up',
    toAccount?: string,
    fromAccount?: string,
): void {
    moveType.value = type;
    fromId.value = fromAccount ?? fromOptions.value[0]?.id ?? '';
    toId.value = toAccount ?? toOptions.value[0]?.id ?? '';
    amountText.value = '';
    reason.value = '';
    errors.value = {};
    moveOpen.value = true;
}

const amountKobo = computed(() => parseNaira(amountText.value));

function submitMove(): void {
    if (!amountKobo.value) {
        errors.value = { amount: 'Enter an amount, e.g. 50,000' };

        return;
    }

    router.post(
        FloatController.store.url(),
        {
            type: moveType.value,
            from_account_id:
                moveType.value === 'opening_balance' ? null : fromId.value,
            to_account_id: toId.value,
            amount: amountKobo.value,
            reason: reason.value || null,
        },
        {
            preserveScroll: true,
            onStart: () => (moving.value = true),
            onFinish: () => (moving.value = false),
            onError: (e) => (errors.value = e),
            onSuccess: () => (moveOpen.value = false),
        },
    );
}

function approve(m: Movement): void {
    router.post(
        FloatController.approve.url(m.id),
        {},
        { preserveScroll: true },
    );
}

function reject(m: Movement): void {
    const note =
        m.can_cancel && !m.can_approve
            ? null
            : window.prompt('Reason for rejecting (optional)');

    if (note === null && !(m.can_cancel && !m.can_approve)) {
        return;
    }

    router.post(
        FloatController.reject.url(m.id),
        { note: note || null },
        { preserveScroll: true },
    );
}

// ---- Alert level dialog -----------------------------------------------------------
const alertFor = ref<Account | null>(null);
const alertText = ref('');

function openAlert(account: Account): void {
    alertFor.value = account;
    alertText.value = koboToInput(account.low_balance_threshold);
}

function saveAlert(): void {
    if (!alertFor.value) {
        return;
    }

    router.patch(
        FloatController.threshold.url(alertFor.value.id),
        {
            low_balance_threshold:
                alertText.value.trim() === ''
                    ? null
                    : parseNaira(alertText.value),
        },
        { preserveScroll: true, onSuccess: () => (alertFor.value = null) },
    );
}

const when = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
});
const statusTone = (s: Movement['status']) =>
    s === 'completed' ? 'gain' : s === 'requested' ? 'pending' : 'neutral';
</script>

<template>
    <Head title="Float" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Float"
            description="Cash in the drawers and money in the provider wallets, right now."
        >
            <template #actions>
                <Button data-test="move-money" @click="openMove()"
                    ><Plus class="size-4" /> Move money</Button
                >
            </template>
        </PageHeader>

        <!-- Awaiting approval -->
        <section
            v-if="awaiting.length"
            aria-labelledby="awaiting-heading"
            class="rounded-2xl border border-pending/30 bg-pending-soft/50 p-4"
        >
            <h2
                id="awaiting-heading"
                class="flex items-center gap-2 font-semibold"
            >
                <Clock class="size-4 text-pending" /> Waiting for approval
            </h2>
            <ul class="mt-3 grid gap-2">
                <li
                    v-for="m in awaiting"
                    :key="m.id"
                    class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">
                            <Amount :kobo="m.amount" />
                            <span class="font-normal text-muted-foreground"
                                >· {{ m.type_label }}</span
                            >
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground"
                        >
                            {{ m.from }} <ArrowRight class="size-3.5" />
                            {{ m.to }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ m.requested_by }} ·
                            {{ when.format(new Date(m.created_at))
                            }}<span v-if="m.reason"> · “{{ m.reason }}”</span>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            v-if="m.can_approve"
                            size="sm"
                            @click="approve(m)"
                            ><Check class="size-4" /> Approve</Button
                        >
                        <Button
                            v-if="m.can_approve || m.can_cancel"
                            size="sm"
                            variant="outline"
                            @click="reject(m)"
                        >
                            <X class="size-4" />
                            {{ m.can_approve ? 'Reject' : 'Cancel' }}
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Head office -->
        <section
            v-if="headOffice"
            class="flex flex-wrap items-center gap-4 rounded-2xl border bg-card p-4"
        >
            <span
                class="grid size-11 place-items-center rounded-xl bg-primary text-primary-foreground"
                ><Landmark class="size-5"
            /></span>
            <div class="flex-1">
                <p class="text-sm text-muted-foreground">Head office</p>
                <p class="text-xl font-bold tracking-tight">
                    <Amount :kobo="headOffice.balance" />
                </p>
            </div>
            <p class="max-w-xs text-xs text-muted-foreground">
                Money held centrally (your bank or safe) for topping up
                outlets.<template v-if="headOffice.balance === 0">
                    Set its opening balance with “Move money”.</template
                >
            </p>
        </section>

        <!-- Outlets -->
        <div
            v-if="outlets.length === 0"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-14 text-center"
        >
            <span
                class="grid size-12 place-items-center rounded-full bg-gold/15 text-gold"
            >
                <Store class="size-6" />
            </span>
            <h2 class="text-lg font-semibold">No outlets yet</h2>
            <p class="max-w-sm text-sm text-muted-foreground">
                Float lives in each outlet's drawer and wallets. Add an outlet,
                then set its opening cash and wallet balances here.
            </p>
            <Button v-if="can('manage_outlets')" as-child>
                <Link :href="outletsIndex()"
                    ><Plus class="size-4" /> Add an outlet</Link
                >
            </Button>
        </div>

        <section
            v-else
            aria-label="Outlets"
            class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <article
                v-for="o in outlets"
                :key="o.id"
                class="flex flex-col rounded-2xl border bg-card"
            >
                <header class="flex items-start justify-between gap-2 p-4 pb-3">
                    <div class="flex items-center gap-2">
                        <Building2 class="size-4 text-muted-foreground" />
                        <h2 class="font-semibold">{{ o.name }}</h2>
                    </div>
                    <div class="text-right">
                        <p
                            class="text-[11px] tracking-wide text-muted-foreground uppercase"
                        >
                            Total float
                        </p>
                        <p class="font-bold">
                            <Amount :kobo="totalFloat(o)" hide-kobo />
                        </p>
                    </div>
                </header>

                <div class="mx-4 rounded-xl bg-muted/60 p-3.5">
                    <div class="flex items-center justify-between gap-2">
                        <p
                            class="flex items-center gap-1.5 text-sm text-muted-foreground"
                        >
                            <Banknote class="size-4" /> Cash in drawer
                        </p>
                        <StatusPill v-if="o.cash.is_low" tone="warning"
                            >Low</StatusPill
                        >
                    </div>
                    <p
                        class="mt-1 text-2xl font-bold tracking-tight"
                        :class="{ 'text-loss': o.cash.balance < 0 }"
                    >
                        <Amount :kobo="o.cash.balance" />
                    </p>
                    <button
                        v-if="isOwner"
                        type="button"
                        class="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                        @click="openAlert(o.cash)"
                    >
                        <Bell class="size-3" />
                        <template v-if="o.cash.low_balance_threshold !== null"
                            >Alert below
                            <Amount
                                :kobo="o.cash.low_balance_threshold"
                                hide-kobo
                        /></template>
                        <template v-else>Set low-cash alert</template>
                    </button>
                </div>

                <ul class="grid gap-1 p-4 pt-3">
                    <li
                        v-for="w in o.wallets"
                        :key="w.id"
                        class="flex items-center justify-between gap-2 rounded-lg px-1 py-1.5"
                    >
                        <span class="flex items-center gap-2 text-sm">
                            <ProviderDot :color="w.color" />
                            <Wallet class="size-3.5 text-muted-foreground" />
                            {{ w.label }}
                            <StatusPill v-if="w.is_low" tone="warning"
                                >Low</StatusPill
                            >
                        </span>
                        <span class="flex items-center gap-2">
                            <span
                                class="font-semibold"
                                :class="{ 'text-loss': w.balance < 0 }"
                                ><Amount :kobo="w.balance"
                            /></span>
                            <button
                                v-if="isOwner"
                                type="button"
                                class="rounded p-1 text-muted-foreground hover:text-foreground"
                                :aria-label="`Low-balance alert for ${o.name} ${w.label}`"
                                @click="openAlert(w)"
                            >
                                <Bell class="size-3.5" />
                            </button>
                        </span>
                    </li>
                </ul>

                <p
                    v-if="o.pending_payouts > 0"
                    class="mx-4 mb-4 rounded-lg bg-pending-soft px-3 py-2 text-xs text-pending"
                >
                    <Amount :kobo="o.pending_payouts" /> paid out on withdrawals
                    still waiting for the provider.
                </p>

                <footer class="mt-auto flex gap-2 border-t p-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="flex-1"
                        @click="openMove('top_up', o.cash.id)"
                        >Top up</Button
                    >
                    <Button
                        variant="ghost"
                        size="sm"
                        class="flex-1"
                        @click="
                            openMove('rebalance', o.wallets[0]?.id, o.cash.id)
                        "
                        >Swap cash ↔ wallet</Button
                    >
                </footer>
            </article>
        </section>

        <!-- History -->
        <section
            aria-labelledby="history-heading"
            class="rounded-2xl border bg-card"
        >
            <h2 id="history-heading" class="px-4 pt-4 font-semibold">
                Recent float movements
            </h2>
            <p
                v-if="history.length === 0"
                class="px-4 pt-1 pb-4 text-sm text-muted-foreground"
            >
                Nothing yet. Start by setting each outlet's opening balance.
            </p>
            <ul v-else class="mt-2 divide-y">
                <li
                    v-for="m in history"
                    :key="m.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ m.type_label }}</p>
                        <p
                            class="flex flex-wrap items-center gap-1 text-xs text-muted-foreground"
                        >
                            <template v-if="m.type !== 'opening_balance'"
                                >{{ m.from }} <ArrowRight class="size-3"
                            /></template>
                            {{ m.to }} ·
                            {{ when.format(new Date(m.created_at)) }}
                        </p>
                    </div>
                    <StatusPill
                        v-if="m.status !== 'completed'"
                        :tone="statusTone(m.status)"
                        class="capitalize"
                        >{{ m.status }}</StatusPill
                    >
                    <span
                        class="font-semibold"
                        :class="{
                            'line-through opacity-50': m.status !== 'completed',
                        }"
                        ><Amount :kobo="m.amount"
                    /></span>
                </li>
            </ul>
        </section>
    </div>

    <!-- Move money -->
    <Dialog v-model:open="moveOpen">
        <DialogContent class="max-h-[92svh] overflow-y-auto sm:max-w-lg">
            <form class="grid gap-5" @submit.prevent="submitMove">
                <DialogHeader>
                    <DialogTitle>Move money</DialogTitle>
                    <DialogDescription>
                        {{
                            isOwner
                                ? 'It happens straight away.'
                                : 'Your supervisor or the owner approves it before any balance changes.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        What kind of move?
                    </legend>
                    <label
                        v-for="t in types"
                        :key="t.value"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted"
                    >
                        <input
                            v-model="moveType"
                            type="radio"
                            name="type"
                            :value="t.value"
                            class="mt-1 accent-[var(--primary)]"
                        />
                        <span>
                            <span class="font-semibold">{{ t.label }}</span>
                            <span class="block text-muted-foreground">{{
                                descriptions[t.value]
                            }}</span>
                        </span>
                    </label>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-if="moveType !== 'opening_balance'"
                        class="grid gap-2"
                    >
                        <Label for="from">From</Label>
                        <select
                            id="from"
                            v-model="fromId"
                            class="h-10 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="o in fromOptions"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                    </div>
                    <div
                        class="grid gap-2"
                        :class="{
                            'sm:col-span-2': moveType === 'opening_balance',
                        }"
                    >
                        <Label for="to">{{
                            moveType === 'opening_balance'
                                ? 'Where the money is'
                                : 'To'
                        }}</Label>
                        <select
                            id="to"
                            v-model="toId"
                            class="h-10 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="o in toOptions"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <InputError :message="errors.to_account_id" />

                <div class="grid gap-2">
                    <Label for="amount">Amount (₦)</Label>
                    <Input
                        id="amount"
                        v-model="amountText"
                        inputmode="decimal"
                        placeholder="e.g. 50,000"
                        class="tabular h-12 text-lg font-semibold"
                        autocomplete="off"
                    />
                    <InputError :message="errors.amount" />
                </div>
                <div class="grid gap-2">
                    <Label for="reason">Note (optional)</Label>
                    <Input
                        id="reason"
                        v-model="reason"
                        placeholder="e.g. Morning top-up"
                        autocomplete="off"
                    />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="moving || !toId">
                        <Spinner v-if="moving" />
                        {{ isOwner ? 'Move money' : 'Send request' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <!-- Alert level -->
    <Dialog
        :open="alertFor !== null"
        @update:open="(v) => !v && (alertFor = null)"
    >
        <DialogContent class="sm:max-w-sm">
            <form class="grid gap-4" @submit.prevent="saveAlert">
                <DialogHeader>
                    <DialogTitle>Low-balance alert</DialogTitle>
                    <DialogDescription
                        >We'll flag {{ alertFor?.label.toLowerCase() }} when it
                        drops below this amount. Leave empty to switch
                        off.</DialogDescription
                    >
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="alert">Alert below (₦)</Label>
                    <Input
                        id="alert"
                        v-model="alertText"
                        inputmode="decimal"
                        placeholder="e.g. 150,000"
                        class="tabular"
                    />
                </div>
                <DialogFooter
                    ><Button type="submit">Save alert</Button></DialogFooter
                >
            </form>
        </DialogContent>
    </Dialog>
</template>
