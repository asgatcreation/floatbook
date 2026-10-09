<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Delete, Lock } from '@lucide/vue';
import { onMounted, onUnmounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { store } from '@/routes/unlock';

defineOptions({ layout: { title: 'Screen locked' } });

const props = defineProps<{
    name: string;
    usesPin: boolean;
}>();

const pin = ref('');
const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'del'];

function press(key: string): void {
    if (key === 'del') {
        pin.value = pin.value.slice(0, -1);
    } else if (key !== '' && pin.value.length < 6) {
        pin.value += key;
    }
}

// Physical keyboards work too (number keys and Backspace).
function onKeydown(event: KeyboardEvent): void {
    if (!props.usesPin) {
        return;
    }

    if (/^\d$/.test(event.key)) {
        press(event.key);
    } else if (event.key === 'Backspace') {
        press('del');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onUnmounted(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Head title="Screen locked" />

    <div class="mb-6 flex items-center gap-3 rounded-xl bg-muted px-4 py-3">
        <span
            class="grid size-10 place-items-center rounded-full bg-primary text-primary-foreground"
        >
            <Lock class="size-5" />
        </span>
        <div>
            <p class="font-semibold">{{ props.name }}</p>
            <p class="text-sm text-muted-foreground">
                {{
                    props.usesPin
                        ? 'Enter your PIN to continue.'
                        : 'Enter your password to continue.'
                }}
            </p>
        </div>
    </div>

    <Form
        v-bind="store.form()"
        reset-on-error
        @error="pin = ''"
        class="space-y-4"
        #default="{ errors, processing }"
    >
        <template v-if="props.usesPin">
            <input type="hidden" name="secret" :value="pin" />
            <div
                class="flex justify-center gap-3 py-2"
                role="status"
                :aria-label="`${pin.length} digits entered`"
            >
                <span
                    v-for="index in 6"
                    :key="index"
                    class="size-3.5 rounded-full border-2 border-foreground/40"
                    :class="{
                        'border-foreground bg-foreground': index <= pin.length,
                    }"
                />
            </div>
            <InputError :message="errors.secret" class="text-center" />
            <div class="grid grid-cols-3 gap-2" aria-label="PIN keypad">
                <template v-for="key in keys" :key="key">
                    <span v-if="key === ''" />
                    <Button
                        v-else
                        type="button"
                        variant="outline"
                        class="h-14 text-xl font-semibold"
                        :aria-label="key === 'del' ? 'Delete digit' : key"
                        @click="press(key)"
                    >
                        <Delete v-if="key === 'del'" class="size-5" />
                        <template v-else>{{ key }}</template>
                    </Button>
                </template>
            </div>
        </template>

        <div v-else class="grid gap-2">
            <Label for="secret">Password</Label>
            <PasswordInput
                id="secret"
                name="secret"
                autocomplete="current-password"
                required
                v-focus
            />
            <InputError :message="errors.secret" />
        </div>

        <Button
            type="submit"
            class="h-12 w-full text-base"
            :disabled="processing || (props.usesPin && pin.length < 4)"
            data-test="unlock-button"
        >
            <Spinner v-if="processing" />
            Unlock
        </Button>
    </Form>

    <div class="mt-6 text-center text-sm">
        <TextLink :href="logout()" as="button" class="text-muted-foreground">
            Not you? Sign out
        </TextLink>
    </div>
</template>
