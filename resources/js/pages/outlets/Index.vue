<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CreditCard, MapPin, Pencil, Plus, Store, Users } from '@lucide/vue';
import { ref } from 'vue';
import OutletController from '@/actions/App/Http/Controllers/OutletController';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import ProviderDot from '@/components/ProviderDot.vue';
import StatusPill from '@/components/StatusPill.vue';
import TerminalDialog from '@/components/TerminalDialog.vue';
import type { TerminalRow } from '@/components/TerminalDialog.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { index } from '@/routes/outlets';

type Outlet = {
    id: string;
    name: string;
    code: string;
    address: string | null;
    is_active: boolean;
    staff_count: number;
    terminals: TerminalRow[];
    wallets: {
        id: string;
        provider_id: string;
        label: string;
        terminals: string;
    }[];
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Outlets', href: index() }] },
});

defineProps<{
    outlets: Outlet[];
    providers: { id: string; name: string; color: string }[];
}>();

const dialogOpen = ref(false);
const editing = ref<Outlet | null>(null);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(outlet: Outlet): void {
    editing.value = outlet;
    dialogOpen.value = true;
}

// Terminals: add one to an outlet, or edit one.
const terminalOpen = ref(false);
const terminalOutlet = ref<Outlet | null>(null);
const terminalEditing = ref<TerminalRow | null>(null);

function openTerminal(
    outlet: Outlet,
    terminal: TerminalRow | null = null,
): void {
    terminalOutlet.value = outlet;
    terminalEditing.value = terminal;
    terminalOpen.value = true;
}
</script>

<template>
    <Head title="Outlets" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Outlets"
            description="Each shop, kiosk or stall where your agents serve customers."
        >
            <template #actions>
                <Button data-test="add-outlet" @click="openCreate">
                    <Plus class="size-4" /> Add outlet
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="outlets.length === 0"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-14 text-center"
        >
            <span
                class="grid size-12 place-items-center rounded-full bg-gold/15 text-gold"
            >
                <Store class="size-6" />
            </span>
            <h2 class="text-lg font-semibold">Add your first outlet</h2>
            <p class="max-w-sm text-sm text-muted-foreground">
                Outlets hold their own cash and wallet float. Add one for every
                location, then invite the agents who work there.
            </p>
            <Button @click="openCreate"
                ><Plus class="size-4" /> Add outlet</Button
            >
        </div>

        <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="outlet in outlets"
                :key="outlet.id"
                class="flex flex-col gap-3 rounded-2xl border bg-card p-4"
                :class="{ 'opacity-70': !outlet.is_active }"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="truncate font-semibold">
                            {{ outlet.name }}
                        </h2>
                        <p
                            class="font-mono text-xs tracking-wider text-muted-foreground"
                        >
                            {{ outlet.code }}
                        </p>
                    </div>
                    <StatusPill :tone="outlet.is_active ? 'gain' : 'neutral'">
                        {{ outlet.is_active ? 'Active' : 'Closed' }}
                    </StatusPill>
                </div>
                <div class="grid gap-1.5 text-sm text-muted-foreground">
                    <p v-if="outlet.address" class="flex items-start gap-2">
                        <MapPin class="mt-0.5 size-4 shrink-0" />
                        {{ outlet.address }}
                    </p>
                    <p class="flex items-center gap-2">
                        <Users class="size-4 shrink-0" />
                        {{ outlet.staff_count }}
                        {{
                            outlet.staff_count === 1 ? 'staff member' : 'staff'
                        }}
                    </p>
                </div>
                <!-- Terminals -->
                <div class="rounded-xl border">
                    <p
                        class="flex items-center gap-1.5 border-b px-3 py-2 text-xs font-medium text-muted-foreground"
                    >
                        <CreditCard class="size-3.5" /> Terminals
                    </p>
                    <ul class="divide-y">
                        <li
                            v-for="t in outlet.terminals"
                            :key="t.id"
                            class="flex items-center gap-2 px-3 py-2 text-sm"
                            :class="{ 'opacity-60': !t.is_active }"
                        >
                            <ProviderDot :color="t.provider.color" />
                            <span class="min-w-0 flex-1 leading-tight">
                                <span class="block truncate font-medium">{{
                                    t.name
                                }}</span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{
                                        [
                                            // "Moniepoint POS" already says Moniepoint.
                                            t.name
                                                .toLowerCase()
                                                .includes(
                                                    t.provider.name.toLowerCase(),
                                                )
                                                ? null
                                                : t.provider.name,
                                            t.terminal_code,
                                            t.is_active ? null : 'switched off',
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}</span
                                >
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :aria-label="`Edit ${t.name}`"
                                @click="openTerminal(outlet, t)"
                            >
                                <Pencil class="size-3.5" />
                            </Button>
                        </li>
                    </ul>
                    <button
                        type="button"
                        class="flex w-full items-center gap-1.5 border-t px-3 py-2 text-sm font-medium hover:bg-muted"
                        :data-test="`add-terminal-${outlet.code}`"
                        @click="openTerminal(outlet)"
                    >
                        <Plus class="size-4" /> Add terminal
                    </button>
                </div>

                <div class="mt-auto">
                    <Button
                        variant="outline"
                        size="sm"
                        :aria-label="`Edit ${outlet.name}`"
                        @click="openEdit(outlet)"
                    >
                        <Pencil class="size-4" /> Edit outlet
                    </Button>
                </div>
            </li>
        </ul>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <Form
                :key="editing?.id ?? 'new'"
                v-bind="
                    editing
                        ? OutletController.update.form(editing.id)
                        : OutletController.store.form()
                "
                :options="{ preserveScroll: true }"
                class="grid gap-5"
                @success="dialogOpen = false"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        editing ? `Edit ${editing.name}` : 'Add an outlet'
                    }}</DialogTitle>
                    <DialogDescription>
                        The short code appears on reports and receipts.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="outlet-name">Outlet name</Label>
                    <Input
                        id="outlet-name"
                        name="name"
                        :default-value="editing?.name"
                        placeholder="e.g. Ketu Main"
                        required
                    />
                    <InputError :message="errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="outlet-code">Short code (optional)</Label>
                    <Input
                        id="outlet-code"
                        name="code"
                        :default-value="editing?.code"
                        placeholder="e.g. KETU"
                        class="font-mono uppercase"
                        maxlength="12"
                    />
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="outlet-address">Address (optional)</Label>
                    <Input
                        id="outlet-address"
                        name="address"
                        :default-value="editing?.address ?? ''"
                        placeholder="Street, area, city"
                    />
                    <InputError :message="errors.address" />
                </div>
                <label
                    v-if="editing"
                    class="flex items-center gap-3 rounded-lg border p-3 text-sm"
                >
                    <input type="hidden" name="is_active" value="0" />
                    <Checkbox
                        name="is_active"
                        value="1"
                        :default-value="editing.is_active"
                    />
                    <span>
                        <span class="font-medium">Outlet is open</span>
                        <span class="block text-muted-foreground"
                            >Closed outlets keep their history but can't record
                            new activity.</span
                        >
                    </span>
                </label>

                <DialogFooter>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ editing ? 'Save changes' : 'Add outlet' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>

    <TerminalDialog
        v-if="terminalOutlet"
        v-model:open="terminalOpen"
        :outlet="terminalOutlet"
        :providers="providers"
        :terminal="terminalEditing"
    />
</template>
