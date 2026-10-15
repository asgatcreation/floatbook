<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Laptop, Smartphone } from '@lucide/vue';
import SessionController from '@/actions/App/Http/Controllers/Settings/SessionController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index } from '@/routes/sessions';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Devices', href: index() }] },
});

const props = defineProps<{
    sessions: {
        device: string;
        ip_address: string | null;
        is_current: boolean;
        last_active: string;
    }[];
}>();

const format = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    dateStyle: 'medium',
    timeStyle: 'short',
});

const isPhone = (device: string) => /Android|iPhone/.test(device);
</script>

<template>
    <Head title="Devices" />

    <h1 class="sr-only">Devices</h1>

    <Heading
        variant="small"
        title="Where you're signed in"
        description="If you see a device you don't recognise, sign out everywhere else and change your password."
    />

    <ul class="divide-y rounded-xl border">
        <li
            v-for="(session, i) in props.sessions"
            :key="i"
            class="flex items-center gap-3 p-4"
        >
            <component
                :is="isPhone(session.device) ? Smartphone : Laptop"
                class="size-5 text-muted-foreground"
            />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ session.device }}</p>
                <p class="text-sm text-muted-foreground">
                    {{ session.ip_address ?? 'Unknown IP' }} ·
                    {{ format.format(new Date(session.last_active)) }}
                </p>
            </div>
            <StatusPill v-if="session.is_current" tone="gain"
                >This device</StatusPill
            >
        </li>
    </ul>

    <Form
        v-bind="SessionController.destroyOthers.form()"
        :options="{ preserveScroll: true }"
        reset-on-success
        reset-on-error
        class="space-y-4"
        #default="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="password">Confirm your password</Label>
            <PasswordInput
                id="password"
                name="password"
                autocomplete="current-password"
            />
            <InputError :message="errors.password" />
        </div>
        <Button variant="outline" :disabled="processing">
            <Spinner v-if="processing" />
            Sign out of all other devices
        </Button>
    </Form>
</template>
