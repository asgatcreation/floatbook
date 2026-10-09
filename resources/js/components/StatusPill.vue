<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        tone?: 'gain' | 'loss' | 'pending' | 'warning' | 'neutral';
    }>(),
    { tone: 'neutral' },
);

const classes = computed(
    () =>
        ({
            gain: 'bg-gain-soft text-gain',
            loss: 'bg-loss-soft text-loss',
            pending: 'bg-pending-soft text-pending',
            warning: 'bg-warning-soft text-warning',
            neutral: 'bg-muted text-muted-foreground',
        })[props.tone],
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap"
        :class="classes"
    >
        <slot />
    </span>
</template>
