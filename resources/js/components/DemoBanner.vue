<script setup lang="ts">
import { FlaskConical } from '@lucide/vue';
import { useTenant } from '@/composables/useTenant';

/**
 * Shown inside the demo business so nobody mistakes it for their own books.
 */
const { tenant } = useTenant();
</script>

<template>
    <div
        v-if="tenant?.business.is_demo"
        class="flex items-start gap-2.5 border-b border-gold/30 bg-gold/10 px-4 py-2 text-sm md:px-6"
        role="status"
    >
        <FlaskConical
            class="mt-0.5 size-4 shrink-0 text-gold-foreground/70"
            aria-hidden="true"
        />
        <p>
            <strong>This is the demo business.</strong>
            Try anything you like: record entries, count a day, move money. It
            is all rebuilt each night, so nothing here is kept.
        </p>
    </div>
</template>
