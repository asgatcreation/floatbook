<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Share, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import {
    install,
    installEvent,
    isInstalled,
    isIos,
    rememberDismissed,
    wasDismissed,
} from '@/lib/install';

/** A small card on phones offering to put FloatBook on the home screen. */
const ready = ref(false);
const dismissed = ref(true);
const ios = ref(false);

onMounted(() => {
    ios.value = isIos();
    dismissed.value = wasDismissed() || isInstalled();
    ready.value = true;
});

// Never on the entry screen: there the keypad needs every bit of the screen.
const page = usePage();
const show = computed(
    () =>
        ready.value &&
        !dismissed.value &&
        page.component !== 'transactions/Create' &&
        (installEvent.value || ios.value),
);

function close(): void {
    dismissed.value = true;
    rememberDismissed();
}

async function add(): Promise<void> {
    if (await install()) {
        dismissed.value = true;
    }
}
</script>

<template>
    <aside
        v-if="show"
        class="mx-4 mt-3 flex items-start gap-3 rounded-2xl border bg-card p-3.5 md:hidden"
        aria-label="Install FloatBook"
    >
        <AppLogoIcon class="size-10 shrink-0" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">Put FloatBook on this phone</p>
            <p v-if="installEvent" class="mt-0.5 text-xs text-muted-foreground">
                Opens like an app, straight to the entry screen, even with no
                network.
            </p>
            <p v-else class="mt-0.5 text-xs text-muted-foreground">
                Tap
                <Share
                    class="inline size-3.5 align-[-2px]"
                    aria-label="Share"
                />
                then <strong>Add to Home Screen</strong>.
            </p>
            <Button
                v-if="installEvent"
                size="sm"
                class="mt-2.5 bg-gold text-gold-foreground hover:bg-gold/90"
                @click="add"
                >Install</Button
            >
        </div>
        <button
            type="button"
            class="-m-1 grid size-8 place-items-center rounded-lg text-muted-foreground hover:bg-muted"
            aria-label="Not now"
            @click="close"
        >
            <X class="size-4" />
        </button>
    </aside>
</template>
