<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { code as verifyCode, send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Check your email',
        description:
            'We sent a 6-digit code to your email address. Enter it below to confirm it is yours.',
    },
});

defineProps<{
    status?: string;
}>();

const page = usePage();
const code = ref('');
</script>

<template>
    <Head title="Confirm your email" />

    <p class="mb-6 rounded-lg bg-muted px-3 py-2 text-sm">
        Sent to
        <strong class="break-all">{{ page.props.auth.user.email }}</strong>
    </p>

    <div
        v-if="status === 'verification-link-sent'"
        role="status"
        class="mb-4 rounded-lg bg-gain-soft px-3 py-2 text-sm font-medium text-gain"
    >
        A new code is on its way. Only the newest code works.
    </div>

    <Form
        v-bind="verifyCode.form()"
        reset-on-error
        @error="code = ''"
        class="space-y-4"
        #default="{ errors, processing }"
    >
        <input type="hidden" name="code" :value="code" />
        <div class="flex flex-col items-center gap-3">
            <label for="otp" class="sr-only">6-digit code</label>
            <InputOTP
                id="otp"
                v-model="code"
                :maxlength="6"
                inputmode="numeric"
                autocomplete="one-time-code"
                :disabled="processing"
                autofocus
            >
                <InputOTPGroup>
                    <InputOTPSlot
                        v-for="index in 6"
                        :key="index"
                        :index="index - 1"
                        class="size-12 text-lg"
                    />
                </InputOTPGroup>
            </InputOTP>
            <InputError :message="errors.code" />
        </div>

        <Button
            type="submit"
            class="h-11 w-full"
            :disabled="processing || code.length !== 6"
            data-test="verify-code-button"
        >
            <Spinner v-if="processing" />
            Confirm email
        </Button>
    </Form>

    <Form
        v-bind="send.form()"
        class="mt-6 flex flex-col items-center gap-3 text-center text-sm"
        v-slot="{ processing }"
    >
        <p class="text-muted-foreground">
            Didn't get it? Check your spam folder, or
        </p>
        <Button :disabled="processing" variant="secondary" size="sm">
            <Spinner v-if="processing" />
            Send a new code
        </Button>
        <TextLink :href="logout()" as="button" class="text-muted-foreground">
            Log out
        </TextLink>
    </Form>
</template>
