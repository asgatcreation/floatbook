<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { Building2, MapPin, UserRound } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login, logout } from '@/routes';
import { accept } from '@/routes/invitations';

const props = defineProps<{
    token: string;
    businessName: string;
    email: string;
    roleLabel: string;
    roleDescription: string;
    outlets: string[];
    inviterName: string | null;
    mode: 'new' | 'login' | 'accept' | 'wrong_account';
}>();

setLayoutProps({
    title: `Join ${props.businessName}`,
    description: props.inviterName
        ? `${props.inviterName} invited you to FloatBook.`
        : 'You have been invited to FloatBook.',
});
</script>

<template>
    <Head :title="`Join ${props.businessName}`" />

    <dl class="mb-6 grid gap-3 rounded-xl bg-muted p-4 text-sm">
        <div class="flex items-start gap-3">
            <Building2 class="mt-0.5 size-4 text-muted-foreground" />
            <div>
                <dt class="sr-only">Business</dt>
                <dd class="font-semibold">{{ props.businessName }}</dd>
            </div>
        </div>
        <div class="flex items-start gap-3">
            <UserRound class="mt-0.5 size-4 text-muted-foreground" />
            <div>
                <dt class="sr-only">Role</dt>
                <dd>
                    <span class="font-semibold">{{ props.roleLabel }}</span>
                    <span class="block text-muted-foreground">{{
                        props.roleDescription
                    }}</span>
                </dd>
            </div>
        </div>
        <div v-if="props.outlets.length" class="flex items-start gap-3">
            <MapPin class="mt-0.5 size-4 text-muted-foreground" />
            <div>
                <dt class="sr-only">Outlets</dt>
                <dd>{{ props.outlets.join(', ') }}</dd>
            </div>
        </div>
    </dl>

    <Form
        v-if="props.mode === 'new'"
        v-bind="accept.form(props.token)"
        :reset-on-success="['password', 'password_confirmation']"
        class="grid gap-5"
        #default="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="email">Email</Label>
            <Input id="email" :model-value="props.email" disabled />
        </div>
        <div class="grid gap-2">
            <Label for="name">Your full name</Label>
            <Input id="name" name="name" autocomplete="name" required v-focus />
            <InputError :message="errors.name" />
        </div>
        <div class="grid gap-2">
            <Label for="password">Choose a password</Label>
            <PasswordInput
                id="password"
                name="password"
                autocomplete="new-password"
                required
            />
            <InputError :message="errors.password" />
        </div>
        <div class="grid gap-2">
            <Label for="password_confirmation">Confirm password</Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                autocomplete="new-password"
                required
            />
        </div>
        <Button type="submit" class="h-11 w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Accept and create account
        </Button>
    </Form>

    <Form
        v-else-if="props.mode === 'accept'"
        v-bind="accept.form(props.token)"
        #default="{ processing }"
    >
        <Button type="submit" class="h-11 w-full" :disabled="processing">
            <Spinner v-if="processing" />
            Accept invitation
        </Button>
    </Form>

    <div v-else-if="props.mode === 'login'" class="grid gap-4 text-sm">
        <p>
            You already have a FloatBook account as
            <strong>{{ props.email }}</strong
            >. Sign in to accept.
        </p>
        <Form v-bind="accept.form(props.token)" #default="{ processing }">
            <Button type="submit" class="h-11 w-full" :disabled="processing">
                Sign in to accept
            </Button>
        </Form>
    </div>

    <div v-else class="grid gap-4 text-sm">
        <p>
            This invitation is for <strong>{{ props.email }}</strong
            >, but you are signed in with a different account.
        </p>
        <TextLink :href="logout()" as="button">
            Sign out, then open the link again
        </TextLink>
    </div>

    <p
        v-if="props.mode === 'new'"
        class="mt-6 text-center text-sm text-muted-foreground"
    >
        Already have an account?
        <TextLink :href="login()">Sign in</TextLink>
    </p>
</template>
