import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Permission } from '@/types';

/**
 * The current business and the signed-in member's role and permissions.
 * The server is always the real gatekeeper; this only decides what to show.
 */
export function useTenant() {
    const page = usePage();
    const tenant = computed(() => page.props.tenant);

    const can = (permission: Permission): boolean =>
        tenant.value?.membership.permissions.includes(permission) ?? false;

    return { tenant, can };
}
