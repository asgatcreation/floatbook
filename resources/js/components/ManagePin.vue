<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import PinController from '@/actions/App/Http/Controllers/Settings/PinController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTenant } from '@/composables/useTenant';

const { tenant } = useTenant();

function removePin(): void {
    if (
        window.confirm(
            'Remove your PIN? You will unlock with your password instead.',
        )
    ) {
        router.delete(PinController.destroy.url(), { preserveScroll: true });
    }
}
</script>

<template>
    <div v-if="tenant" class="space-y-6">
        <Heading
            variant="small"
            title="Quick-unlock PIN"
            :description="`A 4 to 6 digit PIN to unlock FloatBook quickly on a shared counter phone at ${tenant.business.name}.`"
        />

        <Form
            v-bind="PinController.update.form()"
            :options="{ preserveScroll: true }"
            reset-on-success
            reset-on-error
            class="space-y-5"
            #default="{ errors, processing }"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="pin">{{
                        tenant.membership.has_pin ? 'New PIN' : 'PIN'
                    }}</Label>
                    <Input
                        id="pin"
                        name="pin"
                        type="password"
                        inputmode="numeric"
                        autocomplete="off"
                        maxlength="6"
                        pattern="\d{4,6}"
                        class="tracking-[0.4em]"
                    />
                    <InputError :message="errors.pin" />
                </div>
                <div class="grid gap-2">
                    <Label for="pin_confirmation">Repeat PIN</Label>
                    <Input
                        id="pin_confirmation"
                        name="pin_confirmation"
                        type="password"
                        inputmode="numeric"
                        autocomplete="off"
                        maxlength="6"
                        class="tracking-[0.4em]"
                    />
                </div>
            </div>
            <div class="grid gap-2">
                <Label for="pin_current_password">Your password</Label>
                <PasswordInput
                    id="pin_current_password"
                    name="current_password"
                    autocomplete="current-password"
                />
                <InputError :message="errors.current_password" />
            </div>
            <div class="flex flex-wrap gap-3">
                <Button :disabled="processing" data-test="save-pin">
                    <Spinner v-if="processing" />
                    {{ tenant.membership.has_pin ? 'Change PIN' : 'Set PIN' }}
                </Button>
                <Button
                    v-if="tenant.membership.has_pin"
                    type="button"
                    variant="ghost"
                    @click="removePin"
                    >Remove PIN</Button
                >
            </div>
        </Form>
    </div>
</template>
