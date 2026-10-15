<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { FileSpreadsheet, Upload } from '@lucide/vue';
import StatementImportController from '@/actions/App/Http/Controllers/StatementImportController';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, show } from '@/routes/imports';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Statement imports', href: index() }] },
});

defineProps<{
    imports: {
        id: string;
        file: string;
        outlet: string;
        provider: { name: string; color: string };
        status: 'uploaded' | 'mapped' | 'committed';
        rows: number;
        uploaded_by: string | null;
        created_at: string;
    }[];
    outlets: { id: string; name: string }[];
    providers: { id: string; name: string; color: string }[];
}>();

const statusText = {
    uploaded: 'Needs columns',
    mapped: 'Checked',
    committed: 'Imported',
} as const;
const statusTone = {
    uploaded: 'warning',
    mapped: 'pending',
    committed: 'gain',
} as const;
const when = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    day: 'numeric',
    month: 'short',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
});
</script>

<template>
    <Head title="Statement imports" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Statement imports"
            description="Check a provider's statement against what was recorded at the counter, and add anything that was missed."
        />

        <section class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <Form
                v-bind="StatementImportController.store.form()"
                class="grid gap-5 rounded-2xl border bg-card p-5"
                #default="{ errors, processing }"
            >
                <div>
                    <h2 class="font-semibold">Upload a statement</h2>
                    <p class="text-sm text-muted-foreground">
                        Download the transaction history from your provider's
                        dashboard as CSV or Excel, then upload it here.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="outlet">Outlet</Label>
                        <select
                            id="outlet"
                            name="outlet_id"
                            class="h-10 rounded-md border bg-transparent px-3 text-sm"
                            required
                        >
                            <option
                                v-for="o in outlets"
                                :key="o.id"
                                :value="o.id"
                            >
                                {{ o.name }}
                            </option>
                        </select>
                        <InputError :message="errors.outlet_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="provider">Provider</Label>
                        <select
                            id="provider"
                            name="provider_id"
                            class="h-10 rounded-md border bg-transparent px-3 text-sm"
                            required
                        >
                            <option
                                v-for="p in providers"
                                :key="p.id"
                                :value="p.id"
                            >
                                {{ p.name }}
                            </option>
                        </select>
                        <InputError :message="errors.provider_id" />
                    </div>
                </div>

                <label
                    for="file"
                    class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center transition hover:bg-muted/50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                >
                    <Upload class="size-6 text-muted-foreground" />
                    <span class="font-medium">Choose a .csv or .xlsx file</span>
                    <span class="text-xs text-muted-foreground"
                        >Up to 5 MB and 5,000 rows. The file isn't kept after
                        it's read.</span
                    >
                    <input
                        id="file"
                        name="file"
                        type="file"
                        accept=".csv,.xlsx"
                        class="mt-2 max-w-full text-sm"
                        required
                    />
                </label>
                <InputError :message="errors.file" />

                <Button type="submit" class="w-fit" :disabled="processing">
                    <Spinner v-if="processing" /> Upload and continue
                </Button>
            </Form>

            <aside class="rounded-2xl border bg-card p-5 text-sm">
                <h2 class="font-semibold">How it works</h2>
                <ol class="mt-3 grid gap-3 text-muted-foreground">
                    <li>
                        <strong class="text-foreground">1. Upload.</strong> We
                        read the rows and guess which column is which.
                    </li>
                    <li>
                        <strong class="text-foreground"
                            >2. Confirm the columns.</strong
                        >
                        Date and amount are needed; reference helps match
                        exactly.
                    </li>
                    <li>
                        <strong class="text-foreground">3. Review.</strong> Each
                        row is matched by reference, or by amount within 15
                        minutes.
                    </li>
                    <li>
                        <strong class="text-foreground"
                            >4. Add what's missing.</strong
                        >
                        Chosen rows become transactions, at the statement's
                        time, never twice.
                    </li>
                </ol>
                <p class="mt-4 rounded-lg bg-muted p-3 text-xs">
                    FloatBook doesn't connect to Moniepoint, OPay or Palmpay
                    directly. Statements are uploaded by you.
                </p>
            </aside>
        </section>

        <section
            aria-labelledby="past-heading"
            class="rounded-2xl border bg-card"
        >
            <h2 id="past-heading" class="px-4 pt-4 font-semibold">
                Recent imports
            </h2>
            <p
                v-if="imports.length === 0"
                class="px-4 pt-1 pb-4 text-sm text-muted-foreground"
            >
                No statements uploaded yet.
            </p>
            <ul v-else class="mt-2 divide-y">
                <li v-for="i in imports" :key="i.id">
                    <Link
                        :href="show(i.id)"
                        class="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/60"
                    >
                        <FileSpreadsheet class="size-5 text-muted-foreground" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ i.file }}</p>
                            <p
                                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                            >
                                <ProviderDot :color="i.provider.color" />
                                {{ i.provider.name }} · {{ i.outlet }} ·
                                {{ i.rows }} rows ·
                                {{ when.format(new Date(i.created_at))
                                }}<template v-if="i.uploaded_by">
                                    · {{ i.uploaded_by }}</template
                                >
                            </p>
                        </div>
                        <StatusPill :tone="statusTone[i.status]">{{
                            statusText[i.status]
                        }}</StatusPill>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
