<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- FloatBook mark: a gold tile with three stacked float bars. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 32 32"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <rect width="32" height="32" rx="9" fill="#E8A317" />
        <rect x="8" y="9" width="16" height="4" rx="2" fill="#10141B" />
        <rect x="8" y="15" width="12" height="4" rx="2" fill="#10141B" />
        <rect x="8" y="21" width="8" height="4" rx="2" fill="#10141B" />
    </svg>
</template>
