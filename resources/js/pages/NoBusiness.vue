<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { logout } from '@/routes';

defineOptions({
    layout: {
        title: "You don't have access to a business",
        description:
            "Your access may have been removed, or you haven't joined a business yet.",
    },
});
</script>

<template>
    <Head title="No access" />

    <p class="text-sm leading-relaxed text-muted-foreground">
        Ask the business owner to invite you again. When they do, open the link
        in the email to get back in.
    </p>
    <div class="mt-6 text-sm">
        <TextLink :href="logout()" as="button">Sign out</TextLink>
    </div>
</template>
