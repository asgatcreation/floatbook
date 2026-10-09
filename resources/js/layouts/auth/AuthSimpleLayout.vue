<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-10 sm:px-6"
    >
        <div class="w-full max-w-md">
            <Link
                :href="home()"
                class="mx-auto mb-6 flex w-fit items-center gap-2.5"
            >
                <AppLogoIcon class="size-9" />
                <span class="text-xl font-bold tracking-tight">FloatBook</span>
            </Link>

            <div
                class="rounded-2xl border bg-card p-6 shadow-sm sm:p-8 dark:shadow-none"
            >
                <div v-if="title || description" class="mb-6 space-y-1.5">
                    <h1 class="text-2xl font-bold tracking-tight">
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>

            <p class="mt-6 text-center text-xs text-muted-foreground">
                The back office for POS and agent-banking businesses.
            </p>
        </div>
        <Toaster />
    </div>
</template>
