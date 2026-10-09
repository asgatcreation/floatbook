<script setup lang="ts">
defineProps<{ color?: string | null }>();
</script>

<template>
    <span
        class="inline-block size-2 shrink-0 rounded-full ring-2 ring-background"
        :style="{ backgroundColor: color ?? 'var(--muted-foreground)' }"
        aria-hidden="true"
    />
</template>
