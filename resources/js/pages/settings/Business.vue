<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import BusinessSettingsController from '@/actions/App/Http/Controllers/Settings/BusinessSettingsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/business';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Business settings', href: edit() }] },
});

defineProps<{
    business: {
        name: string;
        timezone: string;
        closing_time: string;
        require_two_factor: boolean;
        idle_lock_minutes: number;
    };
    timezones: string[];
}>();
</script>

<template>
    <Head title="Business settings" />

    <h1 class="sr-only">Business settings</h1>

    <Heading
        variant="small"
        title="Business"
        description="How your business appears and when its day closes."
    />

    <Form
        v-bind="BusinessSettingsController.update.form()"
        :options="{ preserveScroll: true }"
        class="space-y-6"
        #default="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="name">Business name</Label>
            <Input
                id="name"
                name="name"
                :default-value="business.name"
                required
            />
            <InputError :message="errors.name" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="timezone">Time zone</Label>
                <select
                    id="timezone"
                    name="timezone"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                >
                    <option
                        v-for="zone in timezones"
                        :key="zone"
                        :value="zone"
                        :selected="zone === business.timezone"
                    >
                        {{ zone.replace('Africa/', '').replace('_', ' ') }}
                    </option>
                </select>
                <InputError :message="errors.timezone" />
            </div>
            <div class="grid gap-2">
                <Label for="closing_time">Daily closing time</Label>
                <Input
                    id="closing_time"
                    name="closing_time"
                    type="time"
                    :default-value="business.closing_time"
                    required
                />
                <p class="text-xs text-muted-foreground">
                    Used for the end-of-day count and the daily summary email.
                </p>
                <InputError :message="errors.closing_time" />
            </div>
        </div>

        <Heading
            variant="small"
            title="Security"
            description="Protect your money and your staff's accounts."
        />

        <label class="flex items-start gap-3 rounded-xl border p-4 text-sm">
            <input type="hidden" name="require_two_factor" value="0" />
            <Checkbox
                name="require_two_factor"
                value="1"
                :default-value="business.require_two_factor"
                class="mt-0.5"
            />
            <span>
                <span class="font-medium"
                    >Require two-factor authentication</span
                >
                <span class="block text-muted-foreground">
                    Owners, supervisors and accountants must use an
                    authenticator app to sign in. Agents use a PIN on shared
                    counter devices instead.
                </span>
            </span>
        </label>
        <InputError :message="errors.require_two_factor" />

        <div class="grid gap-2">
            <Label for="idle_lock_minutes"
                >Lock idle counter devices after</Label
            >
            <div class="flex items-center gap-2">
                <Input
                    id="idle_lock_minutes"
                    name="idle_lock_minutes"
                    type="number"
                    min="2"
                    max="120"
                    class="w-24"
                    :default-value="business.idle_lock_minutes"
                />
                <span class="text-sm text-muted-foreground">minutes</span>
            </div>
            <p class="text-xs text-muted-foreground">
                Applies to agents and supervisors who have set a PIN.
            </p>
            <InputError :message="errors.idle_lock_minutes" />
        </div>

        <Button :disabled="processing" data-test="save-business-settings">
            <Spinner v-if="processing" />
            Save
        </Button>
    </Form>
</template>
