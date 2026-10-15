<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight, CircleCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Welcome back',
        description: "Log in to see today's numbers.",
        panel: 'login',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Log in" />

    <p
        v-if="status"
        class="mb-6 flex items-start gap-2 rounded-xl bg-gain-soft px-4 py-3 text-sm font-medium text-gain"
        role="status"
    >
        <CircleCheck class="mt-0.5 size-4 shrink-0" />
        {{ status }}
    </p>

    <PasskeyVerify separator="Or use your email" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="grid gap-5"
    >
        <div class="grid gap-2">
            <Label for="email">Email address</Label>
            <Input
                id="email"
                type="email"
                name="email"
                required
                v-focus
                :tabindex="1"
                autocomplete="email"
                placeholder="you@business.com"
            />
            <InputError :message="errors.email" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-center justify-between">
                <Label for="password">Password</Label>
                <TextLink
                    v-if="canResetPassword"
                    :href="request()"
                    class="text-sm"
                    :tabindex="5"
                >
                    Forgot password?
                </TextLink>
            </div>
            <PasswordInput
                id="password"
                name="password"
                required
                :tabindex="2"
                autocomplete="current-password"
                placeholder="Your password"
            />
            <InputError :message="errors.password" />
        </div>

        <Label
            for="remember"
            class="flex w-fit items-center gap-3 font-normal text-muted-foreground"
        >
            <Checkbox id="remember" name="remember" :tabindex="3" />
            Keep me signed in on this device
        </Label>

        <Button
            type="submit"
            class="mt-1 h-11 w-full rounded-xl bg-gold text-base font-bold text-gold-foreground hover:bg-gold/90"
            :tabindex="4"
            :disabled="processing"
            data-test="login-button"
        >
            <Spinner v-if="processing" />
            Log in
            <ArrowRight v-if="!processing" class="size-4" />
        </Button>
    </Form>
</template>
