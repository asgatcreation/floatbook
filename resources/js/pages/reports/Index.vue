<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, FileSpreadsheet, RotateCcw } from '@lucide/vue';
import { computed, ref } from 'vue';
import Amount from '@/components/Amount.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useBusinessTime } from '@/lib/dates';
import { formatNaira } from '@/lib/money';
import { exportMethod as exportReport, index } from '@/routes/reports';

type Row = {
    key: string;
    label: string;
    sub: string | null;
    count: number;
    volume: number;
    charges: number;
    commissions: number;
    fees: number;
    expenses: number;
    profit: number;
};
type Filters = {
    from: string;
    to: string;
    group: string;
    outlet: string | null;
    terminal: string | null;
    agent: string | null;
    type: string | null;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Reports', href: index() }] },
});

const props = defineProps<{
    filters: Filters;
    rows: Row[];
    total: Omit<Row, 'key' | 'label' | 'sub'>;
    groups: { value: string; label: string }[];
    outlets: { id: string; name: string }[];
    terminals: { id: string; outlet_id: string; label: string }[];
    staff: { id: string; name: string }[];
    types: { value: string; label: string }[];
    canExport: boolean;
}>();

const { today } = useBusinessTime();

// ---- Filters ---------------------------------------------------------------
const form = ref<Filters>({ ...props.filters });

function apply(changes: Partial<Filters> = {}): void {
    const next = { ...form.value, ...changes };
    form.value = next;

    router.get(
        index.url(),
        Object.fromEntries(Object.entries(next).filter(([, v]) => v)),
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

/** Quick ranges people actually ask for. */
function quick(range: 'today' | 'week' | 'month' | 'last-month'): void {
    const t = today();
    const [y, m, d] = t.split('-').map(Number);
    const start = new Date(Date.UTC(y, m - 1, d));
    const iso = (date: Date) => date.toISOString().slice(0, 10);

    const ranges = {
        today: [t, t],
        week: [iso(new Date(Date.UTC(y, m - 1, d - 6))), t],
        month: [iso(new Date(Date.UTC(y, m - 1, 1))), t],
        'last-month': [
            iso(new Date(Date.UTC(y, m - 2, 1))),
            iso(new Date(Date.UTC(y, m - 1, 0))),
        ],
    } as const;

    void start;
    apply({ from: ranges[range][0], to: ranges[range][1] });
}

const terminalsHere = computed(() =>
    props.terminals.filter(
        (t) => !form.value.outlet || t.outlet_id === form.value.outlet,
    ),
);
const filtered = computed(
    () =>
        form.value.outlet ||
        form.value.terminal ||
        form.value.agent ||
        form.value.type,
);
const groupLabel = computed(
    () =>
        props.groups.find((g) => g.value === form.value.group)?.label ?? 'Day',
);

function clearFilters(): void {
    apply({ outlet: null, terminal: null, agent: null, type: null });
}

// ---- Export ----------------------------------------------------------------
function download(format: 'csv' | 'xlsx', detail: boolean): void {
    const params = new URLSearchParams(
        Object.entries({
            ...form.value,
            format,
            detail: detail ? '1' : '',
        }).filter(([, v]) => v) as [string, string][],
    );

    window.location.href = `${exportReport.url()}?${params.toString()}`;
}

/** Widest bar in the table, so the profit bars are comparable. */
const widest = computed(() =>
    Math.max(1, ...props.rows.map((r) => Math.abs(r.profit))),
);
</script>

<template>
    <Head title="Reports" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Reports"
            description="What was recorded over a stretch of days, grouped the way you want to see it."
        >
            <template #actions>
                <DropdownMenu v-if="canExport">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline">
                            <Download class="size-4" /> Download
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60">
                        <DropdownMenuLabel>This summary</DropdownMenuLabel>
                        <DropdownMenuItem @click="download('xlsx', false)">
                            <FileSpreadsheet class="size-4" /> Excel (.xlsx)
                        </DropdownMenuItem>
                        <DropdownMenuItem @click="download('csv', false)">
                            <FileSpreadsheet class="size-4" /> CSV
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel>Every transaction</DropdownMenuLabel>
                        <DropdownMenuItem @click="download('xlsx', true)">
                            <FileSpreadsheet class="size-4" /> Excel (.xlsx)
                        </DropdownMenuItem>
                        <DropdownMenuItem @click="download('csv', true)">
                            <FileSpreadsheet class="size-4" /> CSV
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHeader>

        <!-- Dates and grouping -->
        <section
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4"
            aria-label="Report settings"
        >
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid gap-1.5">
                    <Label for="from" class="text-xs text-muted-foreground"
                        >From</Label
                    >
                    <Input
                        id="from"
                        v-model="form.from"
                        type="date"
                        class="h-10 w-40"
                        @change="apply()"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="to" class="text-xs text-muted-foreground"
                        >To</Label
                    >
                    <Input
                        id="to"
                        v-model="form.to"
                        type="date"
                        class="h-10 w-40"
                        @change="apply()"
                    />
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <Button size="sm" variant="outline" @click="quick('today')"
                        >Today</Button
                    >
                    <Button size="sm" variant="outline" @click="quick('week')"
                        >7 days</Button
                    >
                    <Button size="sm" variant="outline" @click="quick('month')"
                        >This month</Button
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        @click="quick('last-month')"
                        >Last month</Button
                    >
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <fieldset class="grid gap-1.5">
                    <legend class="mb-1.5 text-xs text-muted-foreground">
                        Group by
                    </legend>
                    <div class="flex flex-wrap gap-1.5">
                        <label
                            v-for="g in groups"
                            :key="g.value"
                            class="cursor-pointer rounded-full border px-3 py-1.5 text-sm select-none has-[:checked]:border-primary has-[:checked]:bg-primary has-[:checked]:text-primary-foreground has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="form.group"
                                type="radio"
                                name="group"
                                :value="g.value"
                                class="sr-only"
                                @change="apply()"
                            />
                            {{ g.label }}
                        </label>
                    </div>
                </fieldset>
            </div>

            <div class="flex flex-wrap items-end gap-3 border-t pt-3">
                <div v-if="outlets.length > 1" class="grid gap-1.5">
                    <Label for="f-outlet" class="text-xs text-muted-foreground"
                        >Outlet</Label
                    >
                    <select
                        id="f-outlet"
                        :value="form.outlet ?? ''"
                        class="h-10 rounded-md border bg-transparent px-3 text-sm"
                        @change="
                            apply({
                                outlet:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                                terminal: null,
                            })
                        "
                    >
                        <option value="">All outlets</option>
                        <option v-for="o in outlets" :key="o.id" :value="o.id">
                            {{ o.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label
                        for="f-terminal"
                        class="text-xs text-muted-foreground"
                        >Terminal</Label
                    >
                    <select
                        id="f-terminal"
                        :value="form.terminal ?? ''"
                        class="h-10 rounded-md border bg-transparent px-3 text-sm"
                        @change="
                            apply({
                                terminal:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">All terminals</option>
                        <option
                            v-for="t in terminalsHere"
                            :key="t.id"
                            :value="t.id"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                </div>
                <div v-if="staff.length" class="grid gap-1.5">
                    <Label for="f-agent" class="text-xs text-muted-foreground"
                        >Staff</Label
                    >
                    <select
                        id="f-agent"
                        :value="form.agent ?? ''"
                        class="h-10 rounded-md border bg-transparent px-3 text-sm"
                        @change="
                            apply({
                                agent:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">Everyone</option>
                        <option v-for="s in staff" :key="s.id" :value="s.id">
                            {{ s.name }}
                        </option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <Label for="f-type" class="text-xs text-muted-foreground"
                        >Type</Label
                    >
                    <select
                        id="f-type"
                        :value="form.type ?? ''"
                        class="h-10 rounded-md border bg-transparent px-3 text-sm"
                        @change="
                            apply({
                                type:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">All types</option>
                        <option
                            v-for="t in types"
                            :key="t.value"
                            :value="t.value"
                        >
                            {{ t.label }}
                        </option>
                    </select>
                </div>
                <Button
                    v-if="filtered"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground"
                    @click="clearFilters"
                >
                    <RotateCcw class="size-4" /> Clear
                </Button>
            </div>
        </section>

        <!-- Totals -->
        <section
            aria-label="Totals"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Profit</p>
                <p class="tabular mt-1 text-2xl font-bold">
                    <Amount :kobo="total.profit" hide-kobo />
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Volume</p>
                <p class="tabular mt-1 text-2xl font-bold">
                    <Amount :kobo="total.volume" hide-kobo />
                </p>
                <p class="tabular mt-1 text-xs text-muted-foreground">
                    {{ total.count }} entries
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">
                    Charges + commissions
                </p>
                <p class="tabular mt-1 text-2xl font-bold">
                    <Amount
                        :kobo="total.charges + total.commissions"
                        hide-kobo
                    />
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Fees + expenses</p>
                <p class="tabular mt-1 text-2xl font-bold">
                    <Amount :kobo="total.fees + total.expenses" hide-kobo />
                </p>
            </div>
        </section>

        <!-- Table -->
        <section
            class="rounded-2xl border bg-card"
            :aria-label="`By ${groupLabel.toLowerCase()}`"
        >
            <div
                v-if="rows.length === 0"
                class="px-6 py-14 text-center text-sm text-muted-foreground"
            >
                Nothing recorded in these days.
            </div>
            <div v-else class="overflow-x-auto">
                <table class="tabular w-full min-w-[42rem] text-sm">
                    <caption class="sr-only">
                        Totals by
                        {{
                            groupLabel.toLowerCase()
                        }}
                    </caption>
                    <thead class="text-xs text-muted-foreground">
                        <tr class="border-b">
                            <th
                                scope="col"
                                class="px-4 py-2.5 text-left font-medium"
                            >
                                {{ groupLabel }}
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2.5 text-right font-medium"
                            >
                                Entries
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2.5 text-right font-medium"
                            >
                                Volume
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2.5 text-right font-medium"
                            >
                                Earned
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2.5 text-right font-medium"
                            >
                                Fees
                            </th>
                            <th
                                scope="col"
                                class="px-3 py-2.5 text-right font-medium"
                            >
                                Expenses
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2.5 text-right font-medium"
                            >
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="r in rows"
                            :key="r.key"
                            class="hover:bg-muted/40"
                        >
                            <th
                                scope="row"
                                class="px-4 py-2.5 text-left font-medium"
                            >
                                {{ r.label }}
                                <span
                                    v-if="r.sub"
                                    class="block text-xs font-normal text-muted-foreground"
                                    >{{ r.sub }}</span
                                >
                            </th>
                            <td class="px-3 py-2.5 text-right">
                                {{ r.count }}
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <Amount :kobo="r.volume" hide-kobo />
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <Amount
                                    :kobo="r.charges + r.commissions"
                                    hide-kobo
                                />
                            </td>
                            <td
                                class="px-3 py-2.5 text-right text-muted-foreground"
                            >
                                <Amount :kobo="r.fees" hide-kobo />
                            </td>
                            <td
                                class="px-3 py-2.5 text-right text-muted-foreground"
                            >
                                <Amount :kobo="r.expenses" hide-kobo />
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <span
                                    class="flex items-center justify-end gap-2"
                                >
                                    <span
                                        class="h-1.5 rounded-full"
                                        :class="
                                            r.profit < 0
                                                ? 'bg-loss'
                                                : 'bg-[var(--chart-bar)]'
                                        "
                                        :style="{
                                            width: `${Math.max(2, (Math.abs(r.profit) / widest) * 64)}px`,
                                        }"
                                        aria-hidden="true"
                                    />
                                    <span class="font-semibold">{{
                                        formatNaira(r.profit, { trim: true })
                                    }}</span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 font-semibold">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-left">
                                Total
                            </th>
                            <td class="px-3 py-3 text-right">
                                {{ total.count }}
                            </td>
                            <td class="px-3 py-3 text-right">
                                <Amount :kobo="total.volume" hide-kobo />
                            </td>
                            <td class="px-3 py-3 text-right">
                                <Amount
                                    :kobo="total.charges + total.commissions"
                                    hide-kobo
                                />
                            </td>
                            <td class="px-3 py-3 text-right">
                                <Amount :kobo="total.fees" hide-kobo />
                            </td>
                            <td class="px-3 py-3 text-right">
                                <Amount :kobo="total.expenses" hide-kobo />
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Amount :kobo="total.profit" hide-kobo />
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <p class="text-xs text-muted-foreground">
            Only successful entries count; voided ones are left out. Expenses
            belong to an outlet and a day, so they are not split across
            terminals, staff or types.
        </p>
    </div>
</template>
