<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import TerminalController from '@/actions/App/Http/Controllers/TerminalController';
import InputError from '@/components/InputError.vue';
import ProviderDot from '@/components/ProviderDot.vue';
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

export type TerminalRow = {
    id: string;
    name: string;
    terminal_code: string | null;
    serial_number: string | null;
    is_active: boolean;
    provider: { id: string; name: string; color: string };
    wallet: string;
    staff_count: number;
};

const props = defineProps<{
    outlet: {
        id: string;
        name: string;
        terminals: TerminalRow[];
        wallets: {
            id: string;
            provider_id: string;
            label: string;
            terminals: string;
        }[];
    };
    providers: { id: string; name: string; color: string }[];
    /** The terminal being edited, or null to add one. */
    terminal: TerminalRow | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const providerId = ref('');
const wallet = ref('new');

// Reset the choices each time the dialog opens.
watch(providerId, () => (wallet.value = 'new'));
watch(
    open,
    (isOpen) => {
        if (isOpen && !props.terminal) {
            providerId.value = props.providers[0]?.id ?? '';
            wallet.value = 'new';
        }
    },
    { immediate: true },
);

// Balances this new terminal could share: same provider, same outlet.
const shareable = computed(() =>
    props.outlet.wallets.filter((w) => w.provider_id === providerId.value),
);

const suggestedName = computed(
    () => `Terminal ${props.outlet.terminals.length + 1}`,
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-md">
            <Form
                :key="terminal?.id ?? `new-${outlet.id}`"
                v-bind="
                    terminal
                        ? TerminalController.update.form(terminal.id)
                        : TerminalController.store.form(outlet.id)
                "
                :options="{ preserveScroll: true }"
                class="grid gap-5"
                @success="open = false"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        terminal
                            ? `Edit ${terminal.name}`
                            : `Add a terminal to ${outlet.name}`
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            terminal
                                ? `${terminal.provider.name} · pays into ${terminal.wallet}.`
                                : 'Any POS machine: Moniepoint, OPay, Palmpay or a bank POS. Add the bank under Settings → Charges & providers first.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="terminal-name">Name</Label>
                    <Input
                        id="terminal-name"
                        name="name"
                        :default-value="terminal?.name ?? suggestedName"
                        placeholder="e.g. Terminal 2"
                        maxlength="40"
                        required
                    />
                    <p class="text-xs text-muted-foreground">
                        What your staff call it at the counter.
                    </p>
                    <InputError :message="errors.name" />
                </div>

                <fieldset v-if="!terminal" class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Provider</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label
                            v-for="p in providers"
                            :key="p.id"
                            class="flex min-w-0 cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted"
                        >
                            <input
                                v-model="providerId"
                                type="radio"
                                name="provider_id"
                                :value="p.id"
                                class="sr-only"
                            />
                            <ProviderDot :color="p.color" />
                            <span class="truncate">{{ p.name }}</span>
                        </label>
                    </div>
                    <InputError :message="errors.provider_id" />
                </fieldset>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="terminal-code">Terminal ID</Label>
                        <Input
                            id="terminal-code"
                            name="terminal_code"
                            :default-value="terminal?.terminal_code ?? ''"
                            placeholder="TID on receipts"
                            class="font-mono"
                            maxlength="30"
                        />
                        <InputError :message="errors.terminal_code" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="terminal-serial">Serial number</Label>
                        <Input
                            id="terminal-serial"
                            name="serial_number"
                            :default-value="terminal?.serial_number ?? ''"
                            placeholder="Optional"
                            class="font-mono"
                            maxlength="40"
                        />
                        <InputError :message="errors.serial_number" />
                    </div>
                </div>

                <fieldset v-if="!terminal" class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Balance</legend>
                    <label
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted"
                    >
                        <input
                            v-model="wallet"
                            type="radio"
                            name="wallet"
                            value="new"
                            class="mt-1 accent-[var(--primary)]"
                        />
                        <span>
                            <span class="font-medium">Its own balance</span>
                            <span class="block text-muted-foreground"
                                >This terminal has its own account with the
                                provider.</span
                            >
                        </span>
                    </label>
                    <label
                        v-for="w in shareable"
                        :key="w.id"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted"
                    >
                        <input
                            v-model="wallet"
                            type="radio"
                            name="wallet"
                            :value="w.id"
                            class="mt-1 accent-[var(--primary)]"
                        />
                        <span>
                            <span class="font-medium"
                                >Same account as
                                {{ w.terminals || w.label }}</span
                            >
                            <span class="block text-muted-foreground"
                                >Both terminals pay into the same account, so
                                they show one balance.</span
                            >
                        </span>
                    </label>
                    <InputError :message="errors.wallet" />
                </fieldset>

                <label
                    v-if="terminal"
                    class="flex items-center gap-3 rounded-lg border p-3 text-sm"
                >
                    <input type="hidden" name="is_active" value="0" />
                    <Checkbox
                        name="is_active"
                        value="1"
                        :default-value="terminal.is_active"
                    />
                    <span>
                        <span class="font-medium">In use</span>
                        <span class="block text-muted-foreground"
                            >Switched-off terminals keep their history but can't
                            take new entries.</span
                        >
                    </span>
                </label>

                <DialogFooter>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ terminal ? 'Save changes' : 'Add terminal' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
