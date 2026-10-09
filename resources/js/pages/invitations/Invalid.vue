<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { computed } from 'vue';
import TextLink from '@/components/TextLink.vue';
import { home } from '@/routes';

const props = defineProps<{
    reason: 'missing' | 'expired' | 'revoked' | 'accepted';
}>();

const message = computed(
    () =>
        ({
            missing:
                "We couldn't find this invitation. Check that you copied the whole link.",
            expired:
                'This invitation has expired. Ask the person who invited you to send a new one.',
            revoked:
                'This invitation was cancelled. Ask the business owner if you should still join.',
            accepted:
                'This invitation has already been used. Sign in to continue.',
        })[props.reason],
);

setLayoutProps({ title: "This link doesn't work", description: message.value });
</script>

<template>
    <Head title="Invitation not valid" />

    <TextLink :href="home()" class="text-sm">Go to FloatBook</TextLink>
</template>
