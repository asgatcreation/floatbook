<script setup lang="ts">
import { computed } from 'vue';
import { splitNaira } from '@/lib/money';

/**
 * A naira amount with aligned digits. Kobo are shown smaller and softer so the
 * naira figure reads first. Colour is only used when `tone` says the number is a gain
 * or a loss, and the sign is always shown with it.
 */
const props = withDefaults(
    defineProps<{
        kobo: number;
        tone?: 'auto' | 'gain' | 'loss' | 'none';
        signed?: boolean;
        hideKobo?: boolean;
    }>(),
    { tone: 'none', signed: false, hideKobo: false },
);

const parts = computed(() => splitNaira(props.kobo));

const sign = computed(() => {
    if (props.kobo < 0) {
        return '−';
    }

    return props.signed && props.kobo > 0 ? '+' : '';
});

const colour = computed(() => {
    const tone =
        props.tone === 'auto'
            ? props.kobo > 0
                ? 'gain'
                : props.kobo < 0
                  ? 'loss'
                  : 'none'
            : props.tone;

    return tone === 'gain' ? 'text-gain' : tone === 'loss' ? 'text-loss' : '';
});

const showKobo = computed(() => !props.hideKobo && parts.value.kobo !== '.00');
</script>

<template>
    <span class="tabular whitespace-nowrap" :class="colour"
        >{{ sign }}{{ parts.naira
        }}<span v-if="showKobo" class="text-[0.7em] opacity-60">{{
            parts.kobo
        }}</span></span
    >
</template>
