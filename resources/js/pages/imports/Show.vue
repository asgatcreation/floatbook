<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Check,
    CircleSlash,
    Copy,
    FileQuestion,
    Plus,
    Search,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import StatementImportController from '@/actions/App/Http/Controllers/StatementImportController';
import Amount from '@/components/Amount.vue';
import InputError from '@/components/InputError.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/imports';
import { show as showTransaction } from '@/routes/transactions';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
type Row = {
    id: number;
    row_number: number;
    reference: string | null;
    amount: number | null;
    occurred_at: string | null;
    type: string | null;
    provider_status: string | null;
    match_status: string;
    problem: string | null;
    transaction_id: string | null;
};

const props = defineProps<{
    statement: {
        id: string;
        file: string;
        outlet: string;
        provider: { name: string; color: string };
        status: 'uploaded' | 'mapped' | 'committed';
        headers: string[];
        mapping: Record<string, string | null> | null;
        row_count: number;
    };
    fields: string[];
    required: string[];
    sample: Record<string, string>[];
    rows: Row[];
    notOnStatement: {
        id: string;
        type_label: string;
        amount: number;
        reference: string | null;
        occurred_at: string;
    }[];
    types: { value: string; label: string }[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Statement imports', href: index() },
        { title: props.statement.file, href: show(props.statement.id) },
    ],
});

const fieldLabels: Record<string, string> = {
    date: 'Date and time',
    amount: 'Amount',
    reference: 'Reference / RRN',
    type: 'Transaction type',
    status: 'Status',
};

// ---- Step 2: columns ------------------------------------------------------------
const mapping = reactive<Record<string, string>>(
    Object.fromEntries(
        props.fields.map((f) => [f, props.statement.mapping?.[f] ?? '']),
    ),
);
const mapErrors = ref<Record<string, string>>({});
const checking = ref(false);
const editingColumns = ref(props.statement.status === 'uploaded');

function check(): void {
    router.post(
        StatementImportController.map.url(props.statement.id),
        {
            mapping: Object.fromEntries(
                Object.entries(mapping).map(([k, v]) => [k, v || null]),
            ),
        },
        {
            preserveScroll: true,
            onStart: () => (checking.value = true),
            onFinish: () => (checking.value = false),
            onError: (e) => (mapErrors.value = e),
            onSuccess: () => (editingColumns.value = false),
        },
    );
}

// ---- Step 3: results ---------------------------------------------------------------
const groups = computed(() => {
    const by = (status: string) =>
        props.rows.filter((r) => r.match_status === status);

    return {
        missing: by('missing_in_floatbook'),
        mismatch: [...by('amount_mismatch'), ...by('status_mismatch')],
        matched: by('matched'),
        created: by('created'),
        other: [...by('duplicate'), ...by('invalid'), ...by('provider_failed')],
    };
});

const selected = ref<number[]>(groups.value.missing.map((r) => r.id));
const defaultType = ref('transfer');
const adding = ref(false);
const tab = ref<
    'missing' | 'mismatch' | 'matched' | 'other' | 'not_on_statement'
>(
    groups.value.missing.length
        ? 'missing'
        : groups.value.mismatch.length
          ? 'mismatch'
          : 'matched',
);

const missingTotal = computed(() =>
    groups.value.missing
        .filter((r) => selected.value.includes(r.id))
        .reduce((s, r) => s + (r.amount ?? 0), 0),
);
const untypedSelected = computed(() =>
    groups.value.missing.some((r) => selected.value.includes(r.id) && !r.type),
);

function addMissing(): void {
    router.post(
        StatementImportController.commit.url(props.statement.id),
        { row_ids: selected.value, default_type: defaultType.value },
        {
            preserveScroll: true,
            onStart: () => (adding.value = true),
            onFinish: () => (adding.value = false),
        },
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
const date = (iso: string | null) => (iso ? when.format(new Date(iso)) : '—');
const typeLabel = (v: string | null) =>
    props.types.find((t) => t.value === v)?.label ?? '—';

const otherLabel: Record<string, string> = {
    duplicate: 'Duplicate',
    invalid: "Couldn't read",
    provider_failed: 'Failed at provider',
};
</script>

<template>
    <Head :title="statement.file" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Link
                    :href="index()"
                    class="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" /> All imports
                </Link>
                <h1 class="text-2xl font-bold tracking-tight break-all">
                    {{ statement.file }}
                </h1>
                <p
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <ProviderDot :color="statement.provider.color" />
                    {{ statement.provider.name }} · {{ statement.outlet }} ·
                    {{ statement.row_count }} rows
                </p>
            </div>
            <Button
                v-if="statement.status === 'mapped' && !editingColumns"
                variant="outline"
                size="sm"
                @click="editingColumns = true"
            >
                Change columns
            </Button>
        </div>

        <!-- Step 2: which column is which -->
        <section
            v-if="editingColumns"
            class="grid gap-5 rounded-2xl border bg-card p-5"
        >
            <div>
                <h2 class="font-semibold">Which column is which?</h2>
                <p class="text-sm text-muted-foreground">
                    We've guessed from the column names. Check them against the
                    preview below.
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div v-for="f in fields" :key="f" class="grid gap-1.5">
                    <label :for="`map-${f}`" class="text-sm font-medium">
                        {{ fieldLabels[f] }}
                        <span v-if="required.includes(f)" class="text-loss"
                            >*</span
                        >
                    </label>
                    <select
                        :id="`map-${f}`"
                        v-model="mapping[f]"
                        class="h-10 rounded-md border bg-transparent px-2 text-sm"
                    >
                        <option value="">— Not in this file —</option>
                        <option
                            v-for="h in statement.headers"
                            :key="h"
                            :value="h"
                        >
                            {{ h }}
                        </option>
                    </select>
                    <InputError :message="mapErrors[`mapping.${f}`]" />
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead class="bg-muted/60 text-xs text-muted-foreground">
                        <tr>
                            <th
                                v-for="h in statement.headers"
                                :key="h"
                                class="px-3 py-2 font-medium whitespace-nowrap"
                            >
                                {{ h }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, i) in sample"
                            :key="i"
                            class="border-t"
                        >
                            <td
                                v-for="h in statement.headers"
                                :key="h"
                                class="tabular px-3 py-2 whitespace-nowrap"
                            >
                                {{ row[h] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Button
                class="w-fit"
                :disabled="checking || !mapping.date || !mapping.amount"
                @click="check"
            >
                <Spinner v-if="checking" /><Search v-else class="size-4" />
                Check against FloatBook
            </Button>
        </section>

        <!-- Step 3: results -->
        <template v-if="!editingColumns && statement.status !== 'uploaded'">
            <section
                aria-label="Summary"
                class="grid grid-cols-2 gap-3 md:grid-cols-5"
            >
                <button
                    v-for="card in [
                        {
                            key: 'missing',
                            label: 'Missing in FloatBook',
                            count: groups.missing.length,
                            tone: 'text-warning',
                        },
                        {
                            key: 'mismatch',
                            label: 'Doesn\'t agree',
                            count: groups.mismatch.length,
                            tone: 'text-loss',
                        },
                        {
                            key: 'matched',
                            label: 'Matched',
                            count:
                                groups.matched.length + groups.created.length,
                            tone: 'text-gain',
                        },
                        {
                            key: 'not_on_statement',
                            label: 'Only in FloatBook',
                            count: notOnStatement.length,
                            tone: 'text-pending',
                        },
                        {
                            key: 'other',
                            label: 'Skipped',
                            count: groups.other.length,
                            tone: 'text-muted-foreground',
                        },
                    ] as const"
                    :key="card.key"
                    type="button"
                    class="rounded-2xl border bg-card p-4 text-left transition"
                    :class="
                        tab === card.key
                            ? 'ring-2 ring-primary'
                            : 'hover:border-foreground/20'
                    "
                    :aria-pressed="tab === card.key"
                    @click="tab = card.key"
                >
                    <p class="text-sm text-muted-foreground">
                        {{ card.label }}
                    </p>
                    <p
                        class="tabular mt-1 text-2xl font-bold"
                        :class="card.count ? card.tone : ''"
                    >
                        {{ card.count }}
                    </p>
                </button>
            </section>

            <!-- Missing: choose and add -->
            <section
                v-if="tab === 'missing'"
                class="rounded-2xl border bg-card"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div>
                        <h2 class="font-semibold">
                            On the statement, but never recorded
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Tick the ones that really happened at
                            {{ statement.outlet }}, then add them.
                        </p>
                    </div>
                </div>
                <p
                    v-if="groups.missing.length === 0"
                    class="px-4 pb-4 text-sm text-muted-foreground"
                >
                    <Check class="mr-1 inline size-4 text-gain" /> Nothing is
                    missing.
                    <template v-if="groups.created.length"
                        >{{ groups.created.length }} row(s) were added from this
                        statement.</template
                    >
                </p>
                <template v-else>
                    <ul class="divide-y border-t">
                        <li v-for="r in groups.missing" :key="r.id">
                            <label
                                class="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-muted/40"
                            >
                                <input
                                    v-model="selected"
                                    type="checkbox"
                                    :value="r.id"
                                    class="size-4 accent-[var(--primary)]"
                                    :disabled="statement.status === 'committed'"
                                />
                                <div class="min-w-0 flex-1">
                                    <p class="font-medium">
                                        {{ typeLabel(r.type) }}
                                        <span
                                            v-if="!r.type"
                                            class="text-xs text-warning"
                                            >(type unknown)</span
                                        >
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ date(r.occurred_at)
                                        }}<template v-if="r.reference">
                                            ·
                                            <span class="font-mono">{{
                                                r.reference
                                            }}</span></template
                                        >
                                        · row {{ r.row_number }}
                                    </p>
                                </div>
                                <span class="font-semibold"
                                    ><Amount :kobo="r.amount ?? 0"
                                /></span>
                            </label>
                        </li>
                    </ul>
                    <div
                        v-if="statement.status !== 'committed'"
                        class="flex flex-wrap items-end gap-3 border-t p-4"
                    >
                        <div v-if="untypedSelected" class="grid gap-1.5">
                            <label
                                for="default-type"
                                class="text-sm font-medium"
                                >Type for rows without one</label
                            >
                            <select
                                id="default-type"
                                v-model="defaultType"
                                class="h-10 rounded-md border bg-transparent px-2 text-sm"
                            >
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
                            :disabled="adding || selected.length === 0"
                            @click="addMissing"
                        >
                            <Spinner v-if="adding" /><Plus
                                v-else
                                class="size-4"
                            />
                            Add {{ selected.length }} to FloatBook ·
                            <Amount :kobo="missingTotal" hide-kobo />
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Charges and fees are worked out with your current
                            rules.
                        </p>
                    </div>
                </template>
            </section>

            <!-- Disagreements -->
            <section
                v-if="tab === 'mismatch'"
                class="rounded-2xl border bg-card"
            >
                <h2 class="p-4 font-semibold">
                    FloatBook and the statement don't agree
                </h2>
                <p
                    v-if="groups.mismatch.length === 0"
                    class="px-4 pb-4 text-sm text-muted-foreground"
                >
                    Everything that matched agrees.
                </p>
                <ul v-else class="divide-y border-t">
                    <li
                        v-for="r in groups.mismatch"
                        :key="r.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-3"
                    >
                        <AlertTriangle class="size-5 text-loss" />
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ r.problem }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ date(r.occurred_at)
                                }}<template v-if="r.reference">
                                    ·
                                    <span class="font-mono">{{
                                        r.reference
                                    }}</span></template
                                >
                            </p>
                        </div>
                        <Link
                            v-if="r.transaction_id"
                            :href="showTransaction(r.transaction_id)"
                            class="text-sm underline-offset-2 hover:underline"
                            >Open the transaction</Link
                        >
                    </li>
                </ul>
            </section>

            <!-- Matched -->
            <section
                v-if="tab === 'matched'"
                class="rounded-2xl border bg-card"
            >
                <h2 class="p-4 font-semibold">Matched</h2>
                <ul class="divide-y border-t">
                    <li
                        v-for="r in [...groups.created, ...groups.matched]"
                        :key="r.id"
                        class="flex items-center gap-3 px-4 py-2.5 text-sm"
                    >
                        <Check class="size-4 text-gain" />
                        <span class="flex-1"
                            >{{ date(r.occurred_at)
                            }}<template v-if="r.reference">
                                ·
                                <span class="font-mono">{{
                                    r.reference
                                }}</span></template
                            ></span
                        >
                        <StatusPill
                            v-if="r.match_status === 'created'"
                            tone="gain"
                            >Added</StatusPill
                        >
                        <span class="font-medium"
                            ><Amount :kobo="r.amount ?? 0"
                        /></span>
                    </li>
                </ul>
            </section>

            <!-- Only in FloatBook -->
            <section
                v-if="tab === 'not_on_statement'"
                class="rounded-2xl border bg-card"
            >
                <div class="p-4">
                    <h2 class="font-semibold">
                        Recorded in FloatBook, but not on this statement
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Check these: they may belong to another provider, or
                        need voiding.
                    </p>
                </div>
                <p
                    v-if="notOnStatement.length === 0"
                    class="px-4 pb-4 text-sm text-muted-foreground"
                >
                    None. Every successful entry in this period is on the
                    statement.
                </p>
                <ul v-else class="divide-y border-t">
                    <li v-for="t in notOnStatement" :key="t.id">
                        <Link
                            :href="showTransaction(t.id)"
                            class="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-muted/40"
                        >
                            <FileQuestion class="size-4 text-pending" />
                            <span class="flex-1"
                                >{{ t.type_label }} · {{ date(t.occurred_at)
                                }}<template v-if="t.reference">
                                    ·
                                    <span class="font-mono">{{
                                        t.reference
                                    }}</span></template
                                ></span
                            >
                            <span class="font-medium"
                                ><Amount :kobo="t.amount"
                            /></span>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- Skipped -->
            <section v-if="tab === 'other'" class="rounded-2xl border bg-card">
                <h2 class="p-4 font-semibold">Skipped rows</h2>
                <p
                    v-if="groups.other.length === 0"
                    class="px-4 pb-4 text-sm text-muted-foreground"
                >
                    No rows were skipped.
                </p>
                <ul v-else class="divide-y border-t">
                    <li
                        v-for="r in groups.other"
                        :key="r.id"
                        class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm"
                    >
                        <Copy
                            v-if="r.match_status === 'duplicate'"
                            class="size-4 text-muted-foreground"
                        />
                        <CircleSlash
                            v-else
                            class="size-4 text-muted-foreground"
                        />
                        <StatusPill>{{
                            otherLabel[r.match_status]
                        }}</StatusPill>
                        <span class="flex-1 text-muted-foreground"
                            >Row {{ r.row_number }} · {{ r.problem }}</span
                        >
                        <span v-if="r.amount" class="font-medium"
                            ><Amount :kobo="r.amount"
                        /></span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
