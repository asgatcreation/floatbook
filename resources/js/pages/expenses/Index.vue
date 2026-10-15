<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, HandCoins, Undo2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ExpenseController from '@/actions/App/Http/Controllers/ExpenseController';
import Amount from '@/components/Amount.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
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
import { parseNaira } from '@/lib/money';
import { index } from '@/routes/expenses';

type Expense = {
    id: string;
    category: string;
    category_label: string;
    amount: number;
    description: string | null;
    outlet: string;
    paid_from: string;
    recorded_by: string;
    occurred_at: string;
    voided: boolean;
    void_reason: string | null;
};
type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Expenses', href: index() }] },
});

const props = defineProps<{
    expenses: Paginated<Expense>;
    month: string;
    outletFilter: string | null;
    total: number;
    byCategory: { value: string; label: string; total: number }[];
    categories: { value: string; label: string }[];
    outlets: {
        id: string;
        name: string;
        accounts: { id: string; label: string; balance: number }[];
    }[];
    canRecord: boolean;
    canVoid: boolean;
}>();

const { format, timeZone } = useBusinessTime();

// ---- Month navigation ------------------------------------------------------
const monthLabel = computed(() =>
    new Intl.DateTimeFormat('en-NG', {
        timeZone: 'UTC',
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${props.month}-01T00:00:00Z`)),
);
const thisMonth = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
}).format(new Date());

function shiftMonth(by: number): string {
    const [y, m] = props.month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + by, 1));

    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

function go(params: { month?: string; outlet?: string | null }): void {
    router.get(
        index.url(),
        {
            month: params.month ?? props.month,
            outlet:
                (params.outlet === undefined
                    ? props.outletFilter
                    : params.outlet) || undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}

// ---- Record an expense -----------------------------------------------------
const outletId = ref(props.outlets[0]?.id ?? '');
const outlet = computed(() =>
    props.outlets.find((o) => o.id === outletId.value),
);
const category = ref(props.categories[0]?.value ?? 'other');
const amountText = ref('');
const amount = computed(() => parseNaira(amountText.value) ?? 0); // kobo
const paidFrom = ref(outlet.value?.accounts[0]?.id ?? '');
const idempotencyKey = ref(crypto.randomUUID());

watch(outletId, () => (paidFrom.value = outlet.value?.accounts[0]?.id ?? ''));

function recorded(): void {
    amountText.value = '';
    idempotencyKey.value = crypto.randomUUID();
}

// ---- Void ------------------------------------------------------------------
const voiding = ref<Expense | null>(null);
const voidOpen = computed({
    get: () => voiding.value !== null,
    set: (open) => {
        if (!open) {
            voiding.value = null;
        }
    },
});
</script>

<template>
    <Head title="Expenses" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Expenses"
            description="Rent, fuel, data, salaries: what it costs to keep each outlet running. Profit is counted after these."
        />

        <!-- Month and outlet -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex items-center rounded-xl border bg-card">
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Previous month"
                    @click="go({ month: shiftMonth(-1) })"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <span class="w-36 text-center text-sm font-semibold">{{
                    monthLabel
                }}</span>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Next month"
                    :disabled="month >= thisMonth"
                    @click="go({ month: shiftMonth(1) })"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
            <select
                v-if="outlets.length > 1"
                :value="outletFilter ?? ''"
                class="h-10 rounded-xl border bg-card px-3 text-sm"
                aria-label="Outlet"
                @change="
                    go({ outlet: ($event.target as HTMLSelectElement).value })
                "
            >
                <option value="">All outlets</option>
                <option v-for="o in outlets" :key="o.id" :value="o.id">
                    {{ o.name }}
                </option>
            </select>
        </div>

        <div
            class="grid gap-6"
            :class="{ 'lg:grid-cols-[minmax(0,1fr)_380px]': canRecord }"
        >
            <div class="flex min-w-0 flex-col gap-6">
                <!-- Month summary -->
                <section
                    v-if="total > 0"
                    aria-label="This month"
                    class="rounded-2xl border bg-card p-5"
                >
                    <p class="text-sm text-muted-foreground">
                        Spent in {{ monthLabel }}
                    </p>
                    <p class="tabular mt-1 text-3xl font-bold tracking-tight">
                        <Amount :kobo="total" hide-kobo />
                    </p>
                    <ul v-if="byCategory.length" class="mt-5 grid gap-3">
                        <li v-for="c in byCategory" :key="c.value">
                            <div
                                class="mb-1 flex items-baseline justify-between text-sm"
                            >
                                <span>{{ c.label }}</span>
                                <span class="tabular font-medium"
                                    ><Amount :kobo="c.total" hide-kobo
                                /></span>
                            </div>
                            <div
                                class="h-1.5 overflow-hidden rounded-full bg-muted"
                                aria-hidden="true"
                            >
                                <div
                                    class="h-full rounded-full bg-gold"
                                    :style="{
                                        width: `${Math.max(2, (c.total / total) * 100)}%`,
                                    }"
                                />
                            </div>
                        </li>
                    </ul>
                </section>

                <!-- List -->
                <section
                    aria-label="Expenses"
                    class="rounded-2xl border bg-card"
                >
                    <div
                        v-if="expenses.data.length === 0"
                        class="flex flex-col items-center gap-3 px-6 py-14 text-center"
                    >
                        <span
                            class="grid size-12 place-items-center rounded-full bg-gold/15 text-gold"
                        >
                            <HandCoins class="size-6" />
                        </span>
                        <h2 class="text-lg font-semibold">
                            Nothing spent in {{ monthLabel }}
                        </h2>
                        <p class="max-w-sm text-sm text-muted-foreground">
                            Record rent, fuel, data and other costs here so your
                            profit shows what you really made.
                        </p>
                    </div>
                    <ul v-else class="divide-y">
                        <li
                            v-for="e in expenses.data"
                            :key="e.id"
                            class="flex items-start gap-3 p-4"
                            :class="{ 'opacity-60': e.voided }"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="font-semibold">{{
                                        e.category_label
                                    }}</span>
                                    <StatusPill v-if="e.voided"
                                        >Voided</StatusPill
                                    >
                                </p>
                                <p
                                    v-if="e.description"
                                    class="truncate text-sm"
                                >
                                    {{ e.description }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ e.outlet }} · {{ e.paid_from }} ·
                                    {{ e.recorded_by }} ·
                                    {{ format('dayTime', e.occurred_at) }}
                                </p>
                                <p
                                    v-if="e.voided && e.void_reason"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    Voided: {{ e.void_reason }}
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span
                                    class="tabular font-semibold"
                                    :class="{ 'line-through': e.voided }"
                                    ><Amount :kobo="e.amount"
                                /></span>
                                <Button
                                    v-if="canVoid && !e.voided"
                                    variant="ghost"
                                    size="sm"
                                    class="h-7 px-2 text-xs text-muted-foreground"
                                    @click="voiding = e"
                                >
                                    <Undo2 class="size-3.5" /> Void
                                </Button>
                            </div>
                        </li>
                    </ul>
                    <nav
                        v-if="expenses.links.length > 3"
                        class="flex flex-wrap gap-1 border-t p-3"
                        aria-label="Pages"
                    >
                        <Button
                            v-for="(link, i) in expenses.links"
                            :key="i"
                            size="sm"
                            :variant="link.active ? 'default' : 'ghost'"
                            :disabled="!link.url"
                            @click="link.url && router.get(link.url)"
                            ><span v-html="link.label"
                        /></Button>
                    </nav>
                </section>
            </div>

            <!-- Record -->
            <Form
                v-if="canRecord && outlet"
                v-bind="ExpenseController.store.form()"
                :options="{ preserveScroll: true }"
                class="grid h-fit gap-4 rounded-2xl border bg-card p-5 lg:sticky lg:top-4"
                :transform="(data) => ({ ...data, amount: amount })"
                :reset-on-success="['description']"
                @success="recorded"
                #default="{ errors, processing }"
            >
                <h2 class="font-semibold">Record an expense</h2>
                <input
                    type="hidden"
                    name="idempotency_key"
                    :value="idempotencyKey"
                />

                <div v-if="outlets.length > 1" class="grid gap-2">
                    <Label for="expense-outlet">Outlet</Label>
                    <select
                        id="expense-outlet"
                        v-model="outletId"
                        name="outlet_id"
                        class="h-10 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option v-for="o in outlets" :key="o.id" :value="o.id">
                            {{ o.name }}
                        </option>
                    </select>
                    <InputError :message="errors.outlet_id" />
                </div>
                <input
                    v-else
                    type="hidden"
                    name="outlet_id"
                    :value="outletId"
                />

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">What for</legend>
                    <div class="flex flex-wrap gap-1.5">
                        <label
                            v-for="c in categories"
                            :key="c.value"
                            class="cursor-pointer rounded-full border px-3 py-1.5 text-sm select-none has-[:checked]:border-primary has-[:checked]:bg-primary has-[:checked]:text-primary-foreground has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="category"
                                type="radio"
                                name="category"
                                :value="c.value"
                                class="sr-only"
                            />
                            {{ c.label }}
                        </label>
                    </div>
                    <InputError :message="errors.category" />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="expense-amount">Amount (₦)</Label>
                    <Input
                        id="expense-amount"
                        v-model="amountText"
                        inputmode="decimal"
                        placeholder="e.g. 4,500"
                        class="tabular h-11 text-lg font-semibold"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="errors.amount" />
                </div>

                <div class="grid gap-2">
                    <Label for="expense-from">Paid from</Label>
                    <select
                        id="expense-from"
                        v-model="paidFrom"
                        name="paid_from_account_id"
                        class="h-10 rounded-md border bg-transparent px-3 text-sm"
                    >
                        <option
                            v-for="a in outlet.accounts"
                            :key="a.id"
                            :value="a.id"
                        >
                            {{ a.label }}
                        </option>
                    </select>
                    <InputError :message="errors.paid_from_account_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="expense-note">Note (optional)</Label>
                    <Input
                        id="expense-note"
                        name="description"
                        placeholder="e.g. Diesel for the generator"
                        maxlength="160"
                    />
                    <InputError :message="errors.description" />
                </div>

                <Button
                    type="submit"
                    class="h-11 bg-gold font-bold text-gold-foreground hover:bg-gold/90"
                    :disabled="processing || amount <= 0"
                >
                    <Spinner v-if="processing" />
                    Record
                    <template v-if="amount > 0"
                        >· <Amount :kobo="amount" hide-kobo
                    /></template>
                </Button>
            </Form>
        </div>
    </div>

    <Dialog v-model:open="voidOpen">
        <DialogContent v-if="voiding" class="sm:max-w-md">
            <Form
                v-bind="ExpenseController.void.form(voiding.id)"
                :options="{ preserveScroll: true }"
                class="grid gap-4"
                @success="voiding = null"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>Void this expense?</DialogTitle>
                    <DialogDescription>
                        The money goes back to {{ voiding.paid_from }}. The
                        expense stays in the list, marked as voided, with your
                        reason.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-2">
                    <Label for="void-reason">Reason</Label>
                    <Input
                        id="void-reason"
                        name="reason"
                        placeholder="e.g. Entered twice"
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
                        <Spinner v-if="processing" /> Void expense
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
