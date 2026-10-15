<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

const {
    title = '',
    description = '',
    panel = 'plain',
} = defineProps<{
    title?: string;
    description?: string;
    panel?: 'login' | 'register' | 'plain';
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description" :panel="panel">
        <slot />
    </AuthLayout>
</template>
