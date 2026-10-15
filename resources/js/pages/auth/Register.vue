<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ArrowRight, Mail } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/register';

defineProps<{
    passwordRules: string;
}>();

defineOptions({
    layout: {
        title: 'Start your FloatBook',
        description:
            'Create your business account in a minute. Outlets, terminals and staff come next.',
        panel: 'register',
    },
});

// A rough guide only; the server applies the real password rules.
const password = ref('');
const strength = computed(() => {
    const p = password.value;

    if (!p) {
        return null;
    }

    const score =
        Number(p.length >= 8) +
        Number(p.length >= 12) +
        Number(/[a-z]/.test(p) && /[A-Z]/.test(p)) +
        Number(/\d/.test(p)) +
        Number(/[^A-Za-z0-9]/.test(p));

    return [
        { label: 'Too short', bars: 1, tone: 'bg-loss' },
        { label: 'Weak', bars: 1, tone: 'bg-loss' },
        { label: 'Fair', bars: 2, tone: 'bg-warning' },
        { label: 'Good', bars: 3, tone: 'bg-gain' },
        { label: 'Strong', bars: 4, tone: 'bg-gain' },
        { label: 'Strong', bars: 4, tone: 'bg-gain' },
    ][p.length < 8 ? 0 : score];
});
</script>

<template>
    <Head title="Create your business account" />

    <Form
        v-bind="store.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="grid gap-7"
    >
        <fieldset class="grid gap-5">
            <legend
                class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
            >
                Your business
            </legend>
            <div class="grid gap-2">
                <Label for="business_name">Business name</Label>
                <Input
                    id="business_name"
                    type="text"
                    required
                    v-focus
                    autocomplete="organization"
                    name="business_name"
                    placeholder="e.g. Ade & Sons POS"
                    aria-describedby="business_name_hint"
                />
                <p
                    id="business_name_hint"
                    class="text-xs text-muted-foreground"
                >
                    Your staff see this name when they log in.
                </p>
                <InputError :message="errors.business_name" />
            </div>
        </fieldset>

        <fieldset class="grid gap-5">
            <legend
                class="mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase"
            >
                You, the owner
            </legend>
            <div class="grid gap-2">
                <Label for="name">Full name</Label>
                <Input
                    id="name"
                    type="text"
                    required
                    autocomplete="name"
                    name="name"
                    placeholder="e.g. Adebayo Okafor"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autocomplete="email"
                    name="email"
                    placeholder="you@business.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    required
                    autocomplete="new-password"
                    name="password"
                    placeholder="Create a password"
                    :passwordrules="passwordRules"
                    @input="
                        password = ($event.target as HTMLInputElement).value
                    "
                />
                <div
                    v-if="strength"
                    class="flex items-center gap-3"
                    aria-live="polite"
                >
                    <div class="flex flex-1 gap-1" aria-hidden="true">
                        <span
                            v-for="n in 4"
                            :key="n"
                            class="h-1 flex-1 rounded-full transition-colors"
                            :class="
                                n <= strength.bars ? strength.tone : 'bg-muted'
                            "
                        />
                    </div>
                    <span
                        class="w-16 text-right text-xs text-muted-foreground"
                        >{{ strength.label }}</span
                    >
                </div>
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirm password</Label>
                <PasswordInput
                    id="password_confirmation"
                    required
                    autocomplete="new-password"
                    name="password_confirmation"
                    placeholder="Type it again"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>
        </fieldset>

        <div class="grid gap-3">
            <Button
                type="submit"
                class="h-11 w-full rounded-xl bg-gold text-base font-bold text-gold-foreground hover:bg-gold/90"
                :disabled="processing"
                data-test="register-user-button"
            >
                <Spinner v-if="processing" />
                Create business account
                <ArrowRight v-if="!processing" class="size-4" />
            </Button>
            <p
                class="flex items-center justify-center gap-1.5 text-center text-xs text-muted-foreground"
            >
                <Mail class="size-3.5" aria-hidden="true" />
                Next, we'll email you a 6-digit code to confirm it's you.
            </p>
        </div>
    </Form>
</template>
