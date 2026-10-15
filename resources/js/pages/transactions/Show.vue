<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    BookOpenCheck,
    Check,
    CornerDownRight,
    Undo2,
    X,
} from '@lucide/vue';
import { ref } from 'vue';
import TransactionController from '@/actions/App/Http/Controllers/TransactionController';
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
import { index, show } from '@/routes/transactions';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
type Tx = {
    id: string;
    type_label: string;
    amount: number;
    charge: number;
    provider_fee: number;
    commission: number;
    profit: number;
    status: 'successful' | 'pending' | 'failed' | 'reversed';
    cash_paid_while_pending: boolean;
    voided: boolean;
    void_reason: string | null;
    reference: string | null;
    customer_name: string | null;
    customer_phone: string | null;
    note: string | null;
    provider: { name: string; color: string } | null;
    terminal: string | null;
    replaces_id: string | null;
    outlet: string | null;
    agent: string | null;
    occurred_at: string;
};

const props = defineProps<{
    transaction: Tx;
    entries: {
        id: string;
        type: string;
        memo: string | null;
        created_at: string;
        lines: { account: string; amount: number }[];
    }[];
    canVoid: boolean;
    canResolve: boolean;
    movedTo: { id: string; terminal: string } | null;
    /** Terminals it can be moved to; empty when this person can't move it. */
    moveTargets: { id: string; label: string }[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Transactions', href: index() },
        {
            title: props.transaction.type_label,
            href: show(props.transaction.id),
        },
    ],
});

const voidOpen = ref(false);
const moveOpen = ref(false);
const moveTo = ref(props.moveTargets[0]?.id ?? '');
const resolving = ref<'successful' | 'failed' | null>(null);

function resolve(status: 'successful' | 'failed'): void {
    router.post(
        TransactionController.resolve.url(props.transaction.id),
        { status },
        {
            preserveScroll: true,
            onStart: () => (resolving.value = status),
            onFinish: () => (resolving.value = null),
        },
    );
}

const when = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    dateStyle: 'full',
    timeStyle: 'short',
});
const tone = (t: Tx) =>
    t.voided
        ? 'neutral'
        : t.status === 'successful'
          ? 'gain'
          : t.status === 'pending'
            ? 'pending'
            : 'loss';
</script>

<template>
    <Head :title="transaction.type_label" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 md:p-6">
        <!-- Receipt -->
        <section class="overflow-hidden rounded-2xl border bg-card">
            <div
                class="flex flex-col items-center gap-2 border-b border-dashed px-6 py-8 text-center"
            >
                <StatusPill :tone="tone(transaction)" class="capitalize">
                    {{ transaction.voided ? 'Voided' : transaction.status }}
                </StatusPill>
                <p class="text-sm text-muted-foreground">
                    {{ transaction.type_label }}
                </p>
                <p
                    class="text-4xl font-bold tracking-tight"
                    :class="{ 'line-through opacity-50': transaction.voided }"
                >
                    <Amount :kobo="transaction.amount" />
                </p>
                <p
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <ProviderDot :color="transaction.provider?.color" />
                    {{ transaction.terminal ?? transaction.provider?.name }} ·
                    {{ transaction.outlet }}
                </p>
            </div>

            <dl class="grid gap-x-8 gap-y-3 p-6 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Customer charge</dt>
                    <dd class="font-medium">
                        <Amount :kobo="transaction.charge" />
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Provider fee</dt>
                    <dd class="font-medium">
                        <Amount :kobo="-transaction.provider_fee" />
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Commission</dt>
                    <dd class="font-medium">
                        <Amount :kobo="transaction.commission" />
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="font-semibold">Profit</dt>
                    <dd class="font-semibold">
                        <Amount :kobo="transaction.profit" tone="auto" signed />
                    </dd>
                </div>
                <div
                    class="flex justify-between gap-4 sm:col-span-2 sm:border-t sm:pt-3"
                >
                    <dt class="text-muted-foreground">Recorded</dt>
                    <dd class="text-right">
                        {{ when.format(new Date(transaction.occurred_at)) }} by
                        {{ transaction.agent }}
                    </dd>
                </div>
                <div
                    v-if="transaction.reference"
                    class="flex justify-between gap-4"
                >
                    <dt class="text-muted-foreground">Reference</dt>
                    <dd class="font-mono">{{ transaction.reference }}</dd>
                </div>
                <div
                    v-if="
                        transaction.customer_name || transaction.customer_phone
                    "
                    class="flex justify-between gap-4"
                >
                    <dt class="text-muted-foreground">Customer</dt>
                    <dd class="text-right">
                        {{
                            [
                                transaction.customer_name,
                                transaction.customer_phone,
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}
                    </dd>
                </div>
                <div
                    v-if="transaction.note"
                    class="flex justify-between gap-4 sm:col-span-2"
                >
                    <dt class="text-muted-foreground">Note</dt>
                    <dd class="text-right">{{ transaction.note }}</dd>
                </div>
                <div
                    v-if="transaction.voided"
                    class="rounded-lg bg-muted p-3 sm:col-span-2"
                >
                    <dt class="font-semibold">Why it was voided</dt>
                    <dd class="text-muted-foreground">
                        {{ transaction.void_reason }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- Moved between terminals -->
        <Link
            v-if="movedTo"
            :href="show(movedTo.id)"
            class="flex items-center gap-3 rounded-2xl border border-pending/30 bg-pending-soft px-4 py-3 text-sm text-pending hover:underline"
        >
            <ArrowLeftRight class="size-4 shrink-0" />
            <span
                >Moved to <strong>{{ movedTo.terminal }}</strong
                >. Open the entry that replaced this one.</span
            >
        </Link>
        <Link
            v-if="transaction.replaces_id"
            :href="show(transaction.replaces_id)"
            class="flex items-center gap-3 rounded-2xl border bg-card px-4 py-3 text-sm text-muted-foreground hover:underline"
        >
            <CornerDownRight class="size-4 shrink-0" />
            <span
                >Moved here from another terminal. See the original entry.</span
            >
        </Link>

        <!-- Actions -->
        <section
            v-if="canResolve || canVoid || moveTargets.length"
            class="flex flex-wrap gap-2"
        >
            <template v-if="canResolve">
                <p class="w-full text-sm text-muted-foreground">
                    Waiting on the provider{{
                        transaction.cash_paid_while_pending
                            ? ' — cash was already paid out'
                            : ''
                    }}. What happened?
                </p>
                <Button
                    :disabled="resolving !== null"
                    @click="resolve('successful')"
                >
                    <Spinner v-if="resolving === 'successful'" /><Check
                        v-else
                        class="size-4"
                    />
                    It went through
                </Button>
                <Button
                    variant="outline"
                    class="text-loss"
                    :disabled="resolving !== null"
                    @click="resolve('failed')"
                >
                    <Spinner v-if="resolving === 'failed'" /><X
                        v-else
                        class="size-4"
                    />
                    It failed
                </Button>
            </template>
            <Button
                v-if="moveTargets.length"
                variant="outline"
                class="ml-auto"
                data-test="move-terminal"
                @click="moveOpen = true"
            >
                <ArrowLeftRight class="size-4" /> Wrong terminal?
            </Button>
            <Button
                v-if="canVoid"
                variant="ghost"
                class="text-muted-foreground"
                :class="{ 'ml-auto': !moveTargets.length }"
                @click="voidOpen = true"
            >
                <Undo2 class="size-4" /> Void this entry
            </Button>
        </section>

        <!-- Ledger -->
        <section
            aria-labelledby="ledger-heading"
            class="rounded-2xl border bg-card p-5"
        >
            <h2
                id="ledger-heading"
                class="flex items-center gap-2 font-semibold"
            >
                <BookOpenCheck class="size-4 text-muted-foreground" /> Ledger
                entries
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Every entry balances to zero: money that goes in somewhere came
                out of somewhere else.
            </p>
            <p
                v-if="entries.length === 0"
                class="mt-4 text-sm text-muted-foreground"
            >
                Nothing posted — no money has moved for this entry.
            </p>
            <div
                v-for="entry in entries"
                :key="entry.id"
                class="mt-4 overflow-x-auto"
            >
                <p
                    class="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    {{ entry.memo ?? entry.type }}
                </p>
                <table class="tabular w-full min-w-[22rem] text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 text-left font-medium">Account</th>
                            <th class="py-1 text-right font-medium">In</th>
                            <th class="py-1 text-right font-medium">Out</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(line, i) in entry.lines"
                            :key="i"
                            class="border-t"
                        >
                            <td class="py-1.5 pr-4">{{ line.account }}</td>
                            <td class="py-1.5 text-right">
                                <Amount
                                    v-if="line.amount > 0"
                                    :kobo="line.amount"
                                />
                            </td>
                            <td class="py-1.5 text-right">
                                <Amount
                                    v-if="line.amount < 0"
                                    :kobo="-line.amount"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <Dialog v-model:open="voidOpen">
        <DialogContent class="sm:max-w-md">
            <Form
                v-bind="TransactionController.void.form(transaction.id)"
                :options="{ preserveScroll: true }"
                class="grid gap-4"
                @success="voidOpen = false"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle
                        >Void this
                        {{ transaction.type_label.toLowerCase() }}?</DialogTitle
                    >
                    <DialogDescription>
                        The money it moved will be reversed. The entry stays in
                        the history, marked as voided, with your reason.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="reason">Reason</Label>
                    <Input
                        id="reason"
                        name="reason"
                        placeholder="e.g. Entered twice"
                        required
                        v-focus
                    />
                    <InputError :message="errors.reason" />
                </div>
                <DialogFooter>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                    >
                        <Spinner v-if="processing" /> Void entry
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
    <Dialog v-model:open="moveOpen">
        <DialogContent class="sm:max-w-md">
            <Form
                v-bind="TransactionController.move.form(transaction.id)"
                class="grid gap-4"
                @success="moveOpen = false"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Move to another terminal</DialogTitle>
                    <DialogDescription>
                        Recorded on {{ transaction.terminal }} by mistake? It is
                        moved, not deleted: this entry is kept, marked as moved,
                        and the same transaction is recorded on the right
                        terminal at the same time.
                    </DialogDescription>
                </DialogHeader>
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        The right terminal
                    </legend>
                    <label
                        v-for="t in moveTargets"
                        :key="t.id"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted"
                    >
                        <input
                            v-model="moveTo"
                            type="radio"
                            name="terminal_id"
                            :value="t.id"
                            class="accent-[var(--primary)]"
                        />
                        {{ t.label }}
                    </label>
                    <InputError :message="errors.terminal_id" />
                </fieldset>
                <div class="grid gap-2">
                    <Label for="move-reason">Reason</Label>
                    <Input
                        id="move-reason"
                        name="reason"
                        :placeholder="`e.g. Customer paid on ${moveTargets.find((t) => t.id === moveTo)?.label ?? 'the other terminal'}`"
                        required
                    />
                    <InputError :message="errors.reason" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="processing || !moveTo">
                        <Spinner v-if="processing" /> Move entry
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
