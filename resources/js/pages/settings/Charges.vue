<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ChargeRuleController from '@/actions/App/Http/Controllers/Settings/ChargeRuleController';
import Heading from '@/components/Heading.vue';
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
import type { Tier } from '@/lib/charges';
import { describeRange, describeTier } from '@/lib/describeRule';
import { koboToInput, parseNaira } from '@/lib/money';
import { index } from '@/routes/charges';

type RuleRow = {
    id: string;
    transaction_type: string;
    kind: 'customer_charge' | 'provider_fee' | 'provider_commission';
    kind_label: string;
    provider: { id: string; name: string; color: string } | null;
    outlet: { id: string; name: string } | null;
    collected_via: 'cash' | 'wallet';
    is_active: boolean;
    tiers: Tier[];
};
type Provider = { id: string; name: string; color: string; is_active: boolean };

defineOptions({
    layout: { breadcrumbs: [{ title: 'Charges & providers', href: index() }] },
});

const props = defineProps<{
    rules: RuleRow[];
    types: { value: string; label: string; flow: string }[];
    kinds: { value: string; label: string }[];
    providers: Provider[];
    outlets: { id: string; name: string }[];
}>();

const rulesFor = (type: string) =>
    props.rules.filter((r) => r.transaction_type === type);

const kindHelp: Record<string, string> = {
    customer_charge: 'What you collect from the customer',
    provider_fee: 'What the provider deducts from your wallet',
    provider_commission: 'What the provider pays you',
};

// ---- Rule editor --------------------------------------------------------------------------
type TierForm = {
    from: string;
    to: string;
    flat: string;
    percent: string;
    cap: string;
    step: string;
};

const editorOpen = ref(false);
const editing = ref<RuleRow | null>(null);
const newRule = ref({
    transaction_type: 'transfer',
    kind: 'customer_charge',
    provider_id: '',
    outlet_id: '',
});
const collectedVia = ref<'cash' | 'wallet'>('cash');
const active = ref(true);
const tiers = ref<TierForm[]>([]);
const saving = ref(false);
const errors = ref<Record<string, string>>({});

const toForm = (t: Tier): TierForm => ({
    from: koboToInput(t.min_amount),
    to: koboToInput(t.max_amount),
    flat: koboToInput(t.flat),
    percent: t.percent_bps ? String(t.percent_bps / 100) : '',
    cap: koboToInput(t.cap),
    step: koboToInput(t.step),
});

function openEdit(rule: RuleRow): void {
    editing.value = rule;
    collectedVia.value = rule.collected_via;
    active.value = rule.is_active;
    tiers.value = rule.tiers.map(toForm);
    errors.value = {};
    editorOpen.value = true;
}

function openNew(type = 'transfer', kind = 'customer_charge'): void {
    editing.value = null;
    newRule.value = {
        transaction_type: type,
        kind,
        provider_id: props.providers[0]?.id ?? '',
        outlet_id: '',
    };
    collectedVia.value = 'cash';
    active.value = true;
    tiers.value = [
        { from: '0', to: '', flat: '', percent: '', cap: '', step: '' },
    ];
    errors.value = {};
    editorOpen.value = true;
}

function addBand(): void {
    const last = tiers.value.at(-1);
    const lastTo = last ? parseNaira(last.to) : null;
    tiers.value.push({
        from: lastTo !== null ? koboToInput(lastTo + 100) : '',
        to: '',
        flat: '',
        percent: '',
        cap: '',
        step: '',
    });
}

const editingFlow = computed(
    () =>
        props.types.find(
            (t) =>
                t.value ===
                (editing.value?.transaction_type ??
                    newRule.value.transaction_type),
        )?.flow,
);
const editingKind = computed(() => editing.value?.kind ?? newRule.value.kind);

function toTier(f: TierForm): Tier | string {
    const num = (s: string) => (s.trim() === '' ? null : parseNaira(s));
    const from = num(f.from) ?? 0;
    const percent = f.percent.trim() === '' ? 0 : Number(f.percent);

    if (Number.isNaN(percent) || percent < 0 || percent > 100) {
        return 'Percent must be between 0 and 100.';
    }

    for (const [label, value] of [
        ['From', f.from],
        ['To', f.to],
        ['Amount', f.flat],
        ['Max', f.cap],
        ['Per every', f.step],
    ] as const) {
        if (value.trim() !== '' && num(value) === null) {
            return `${label}: “${value}” isn't a valid naira amount.`;
        }
    }

    return {
        min_amount: from,
        max_amount: num(f.to),
        flat: num(f.flat) ?? 0,
        percent_bps: Math.round(percent * 100),
        cap: num(f.cap),
        step: num(f.step),
    };
}

function save(): void {
    const converted = tiers.value.map(toTier);
    const problem = converted.find((t) => typeof t === 'string');

    if (problem) {
        errors.value = { tiers: problem as string };

        return;
    }

    const payload = {
        tiers: converted,
        collected_via: collectedVia.value,
        ...(editing.value
            ? { is_active: active.value }
            : {
                  ...newRule.value,
                  provider_id: newRule.value.provider_id || null,
                  outlet_id: newRule.value.outlet_id || null,
              }),
    };

    const options = {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
        onError: (e: Record<string, string>) => (errors.value = e),
        onSuccess: () => (editorOpen.value = false),
    };

    if (editing.value) {
        router.put(
            ChargeRuleController.update.url(editing.value.id),
            payload,
            options,
        );
    } else {
        router.post(ChargeRuleController.store.url(), payload, options);
    }
}

function removeOverride(rule: RuleRow): void {
    if (
        window.confirm(
            'Remove this override? The general rule will apply again.',
        )
    ) {
        router.delete(ChargeRuleController.destroy.url(rule.id), {
            preserveScroll: true,
        });
    }
}

// ---- Providers ----------------------------------------------------------------------------
const providerName = ref('');
const providerColor = ref('#59616C');
const providerErrors = ref<Record<string, string>>({});

function addProvider(): void {
    router.post(
        ChargeRuleController.storeProvider.url(),
        { name: providerName.value, color: providerColor.value },
        {
            preserveScroll: true,
            onError: (e) => (providerErrors.value = e),
            onSuccess: () => {
                providerName.value = '';
                providerErrors.value = {};
            },
        },
    );
}

function toggleProvider(p: Provider): void {
    router.patch(
        ChargeRuleController.updateProvider.url(p.id),
        { is_active: !p.is_active },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Charges & providers" />

    <h1 class="sr-only">Charges and providers</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Charges, fees and commissions"
            description="FloatBook works out every transaction's charge and profit from these rules. Changes apply to new transactions only — past ones keep the amounts they were saved with."
        />

        <p class="rounded-xl bg-warning-soft px-4 py-3 text-sm text-warning">
            These started as typical Nigerian POS prices. Check them against
            your own prices and your providers' real fees.
        </p>

        <section
            v-for="t in types"
            :key="t.value"
            class="rounded-2xl border bg-card"
        >
            <header class="flex items-center justify-between gap-2 px-4 pt-4">
                <h2 class="font-semibold">{{ t.label }}</h2>
                <Button variant="ghost" size="sm" @click="openNew(t.value)"
                    ><Plus class="size-4" /> Override</Button
                >
            </header>
            <p
                v-if="t.flow === 'service'"
                class="px-4 pt-1 text-xs text-muted-foreground"
            >
                For services, the price the agent enters is the charge.
            </p>

            <ul class="divide-y">
                <li
                    v-if="rulesFor(t.value).length === 0"
                    class="px-4 py-3 text-sm text-muted-foreground"
                >
                    No rules — this earns and costs nothing extra.
                </li>
                <li
                    v-for="rule in rulesFor(t.value)"
                    :key="rule.id"
                    class="flex flex-wrap items-start gap-3 px-4 py-3"
                    :class="{ 'opacity-50': !rule.is_active }"
                >
                    <div class="min-w-0 flex-1 basis-60">
                        <p
                            class="flex flex-wrap items-center gap-2 text-sm font-medium"
                        >
                            {{ rule.kind_label }}
                            <span
                                v-if="rule.provider"
                                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-normal"
                            >
                                <ProviderDot :color="rule.provider.color" />
                                {{ rule.provider.name }} only
                            </span>
                            <span
                                v-if="rule.outlet"
                                class="rounded-full bg-muted px-2 py-0.5 text-xs font-normal"
                                >{{ rule.outlet.name }} only</span
                            >
                            <StatusPill v-if="!rule.is_active">Off</StatusPill>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ kindHelp[rule.kind]
                            }}{{
                                rule.kind === 'customer_charge' &&
                                rule.collected_via === 'wallet'
                                    ? ' · added to the card amount'
                                    : ''
                            }}
                        </p>
                        <ul class="mt-2 grid gap-1 text-sm">
                            <li
                                v-for="(tier, i) in rule.tiers"
                                :key="i"
                                class="flex flex-wrap justify-between gap-x-4 rounded-lg bg-muted/60 px-3 py-1.5"
                            >
                                <span class="text-muted-foreground">{{
                                    describeRange(tier, rule.tiers.length === 1)
                                }}</span>
                                <span class="tabular font-semibold">{{
                                    describeTier(tier)
                                }}</span>
                            </li>
                        </ul>
                    </div>
                    <div class="flex gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            :aria-label="`Edit ${t.label} ${rule.kind_label}`"
                            @click="openEdit(rule)"
                            ><Pencil class="size-4" /> Edit</Button
                        >
                        <Button
                            v-if="rule.provider || rule.outlet"
                            variant="ghost"
                            size="sm"
                            aria-label="Remove override"
                            @click="removeOverride(rule)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                </li>
            </ul>
        </section>

        <Heading
            variant="small"
            title="Providers"
            description="The terminals and apps your outlets use. Each outlet gets a wallet for every provider that's switched on."
        />

        <ul class="divide-y rounded-2xl border bg-card">
            <li
                v-for="p in providers"
                :key="p.id"
                class="flex items-center gap-3 px-4 py-3"
            >
                <ProviderDot :color="p.color" />
                <span
                    class="flex-1 font-medium"
                    :class="{ 'opacity-50': !p.is_active }"
                    >{{ p.name }}</span
                >
                <Button
                    variant="outline"
                    size="sm"
                    @click="toggleProvider(p)"
                    >{{ p.is_active ? 'Switch off' : 'Switch on' }}</Button
                >
            </li>
        </ul>

        <form
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="addProvider"
        >
            <div class="grid flex-1 gap-2">
                <Label for="provider-name">Add a provider</Label>
                <Input
                    id="provider-name"
                    v-model="providerName"
                    placeholder="e.g. Paystack Terminal"
                />
                <InputError :message="providerErrors.name" />
            </div>
            <div class="grid gap-2">
                <Label for="provider-color">Colour</Label>
                <input
                    id="provider-color"
                    v-model="providerColor"
                    type="color"
                    class="h-9 w-14 cursor-pointer rounded-md border bg-transparent"
                />
            </div>
            <Button
                type="submit"
                variant="outline"
                :disabled="providerName.trim().length < 2"
                ><Plus class="size-4" /> Add</Button
            >
        </form>
    </div>

    <!-- Rule editor -->
    <Dialog v-model:open="editorOpen">
        <DialogContent class="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
            <form class="grid gap-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? `${types.find((t) => t.value === editing?.transaction_type)?.label} · ${editing.kind_label}`
                            : 'Add an override'
                    }}</DialogTitle>
                    <DialogDescription>
                        Amounts in naira. Use “Per every” for charges like ₦100
                        for every ₦5,000. Leave “To” empty on the last band for
                        no upper limit.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="!editing" class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="rule-type">Transaction</Label>
                        <select
                            id="rule-type"
                            v-model="newRule.transaction_type"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
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
                    <div class="grid gap-2">
                        <Label for="rule-kind">Applies to</Label>
                        <select
                            id="rule-kind"
                            v-model="newRule.kind"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="k in kinds"
                                :key="k.value"
                                :value="k.value"
                            >
                                {{ k.label }}
                            </option>
                        </select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="rule-provider">Provider</Label>
                        <select
                            id="rule-provider"
                            v-model="newRule.provider_id"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option value="">Every provider</option>
                            <option
                                v-for="p in providers"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name }} only
                            </option>
                        </select>
                        <InputError :message="errors.provider_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="rule-outlet">Outlet</Label>
                        <select
                            id="rule-outlet"
                            v-model="newRule.outlet_id"
                            class="h-9 rounded-md border bg-transparent px-3 text-sm"
                        >
                            <option value="">Every outlet</option>
                            <option
                                v-for="o in outlets"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.name }} only
                            </option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-2">
                    <div
                        class="hidden grid-cols-[1fr_1fr_1fr_0.7fr_1fr_1fr_auto] gap-2 px-1 text-xs font-medium text-muted-foreground sm:grid"
                    >
                        <span>From ₦</span><span>To ₦</span><span>Amount ₦</span
                        ><span>%</span><span>Max ₦</span><span>Per every ₦</span
                        ><span class="w-8" />
                    </div>
                    <div
                        v-for="(band, i) in tiers"
                        :key="i"
                        class="grid grid-cols-2 gap-2 rounded-xl border p-2 sm:grid-cols-[1fr_1fr_1fr_0.7fr_1fr_1fr_auto] sm:border-0 sm:p-0"
                    >
                        <Input
                            v-model="band.from"
                            inputmode="decimal"
                            aria-label="From"
                            placeholder="0"
                            class="tabular"
                        />
                        <Input
                            v-model="band.to"
                            inputmode="decimal"
                            aria-label="To"
                            placeholder="No limit"
                            class="tabular"
                        />
                        <Input
                            v-model="band.flat"
                            inputmode="decimal"
                            aria-label="Flat amount"
                            placeholder="0"
                            class="tabular"
                        />
                        <Input
                            v-model="band.percent"
                            inputmode="decimal"
                            aria-label="Percent"
                            placeholder="0"
                            class="tabular"
                        />
                        <Input
                            v-model="band.cap"
                            inputmode="decimal"
                            aria-label="Maximum"
                            placeholder="—"
                            class="tabular"
                        />
                        <Input
                            v-model="band.step"
                            inputmode="decimal"
                            aria-label="Per every"
                            placeholder="—"
                            class="tabular"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove band"
                            :disabled="tiers.length === 1"
                            @click="tiers.splice(i, 1)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <InputError
                        :message="errors.tiers ?? errors['tiers.0.max_amount']"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="w-fit"
                        @click="addBand"
                        ><Plus class="size-4" /> Add a band</Button
                    >
                </div>

                <fieldset
                    v-if="
                        editingKind === 'customer_charge' &&
                        editingFlow === 'cash_out'
                    "
                    class="grid gap-2"
                >
                    <legend class="mb-1 text-sm font-medium">
                        How the customer pays the charge
                    </legend>
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="collectedVia"
                            type="radio"
                            value="cash"
                            class="accent-[var(--primary)]"
                        />
                        In cash, at the counter</label
                    >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="collectedVia"
                            type="radio"
                            value="wallet"
                            class="accent-[var(--primary)]"
                        />
                        Added to the amount debited from their card</label
                    >
                </fieldset>

                <label
                    v-if="editing && !editing.provider && !editing.outlet"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="active"
                        type="checkbox"
                        class="size-4 accent-[var(--primary)]"
                    />
                    This rule is on
                </label>

                <DialogFooter>
                    <Button type="submit" :disabled="saving"
                        ><Spinner v-if="saving" /> Save rule</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
