<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import { index } from '@/routes/audit-log';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
type Log = {
    id: string;
    action: string;
    actor: string | null;
    actor_role: string | null;
    subject: string | null;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    meta: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Audit log', href: index() }] },
});

defineProps<{
    logs: Paginated<Log>;
    actions: string[];
    filters: { action: string | null };
}>();

// Each label continues the sentence "<person> ...".
const labels: Record<string, string> = {
    'business.created': 'created the business',
    'business.settings_updated': 'changed the business settings',
    'membership.created': 'joined the business',
    'membership.updated': 'changed a member’s role or outlets',
    'membership.suspended': 'removed a member’s access',
    'membership.reactivated': 'restored a member’s access',
    'invitation.sent': 'invited someone',
    'invitation.resent': 're-sent an invitation',
    'invitation.revoked': 'cancelled an invitation',
    'invitation.accepted': 'accepted an invitation',
    'outlet.created': 'added an outlet',
    'outlet.updated': 'changed an outlet',
    'user.signed_in': 'signed in',
    'user.email_verified': 'confirmed their email',
    'user.signed_out_other_devices': 'signed out their other devices',
    'pin.set': 'set a PIN',
    'pin.changed': 'changed their PIN',
    'pin.removed': 'removed their PIN',
    'pin.locked_out': 'was signed out after too many wrong PINs',
};

const categoryLabels: Record<string, string> = {
    business: 'Business',
    membership: 'Staff',
    invitation: 'Invitations',
    outlet: 'Outlets',
    user: 'Sign-ins',
    pin: 'PINs',
};

const format = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    dateStyle: 'medium',
    timeStyle: 'short',
});

function changes(log: Log): { key: string; from: unknown; to: unknown }[] {
    const keys = new Set([
        ...Object.keys(log.before ?? {}),
        ...Object.keys(log.after ?? {}),
    ]);

    return [...keys].map((key) => ({
        key: key.replaceAll('_', ' '),
        from: log.before?.[key],
        to: log.after?.[key],
    }));
}

// Laravel's pagination labels contain HTML entities; render them as plain text.
function pageLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

function show(value: unknown): string {
    if (value === undefined || value === null || value === '') {
        return '—';
    }

    if (Array.isArray(value)) {
        return value.length ? `${value.length} selected` : 'none';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Audit log"
            description="Every important change, who made it and when. Entries can never be edited or deleted."
        />

        <nav class="flex flex-wrap gap-2" aria-label="Filter by type">
            <Link
                :href="index()"
                class="rounded-full border px-3 py-1.5 text-sm"
                :class="
                    !filters.action
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'bg-card'
                "
                preserve-scroll
                >All</Link
            >
            <Link
                v-for="action in actions"
                :key="action"
                :href="index({ query: { action } })"
                class="rounded-full border px-3 py-1.5 text-sm"
                :class="
                    filters.action === action
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'bg-card'
                "
                preserve-scroll
                >{{ categoryLabels[action] ?? action }}</Link
            >
        </nav>

        <ul class="divide-y rounded-2xl border bg-card">
            <li
                v-if="logs.data.length === 0"
                class="p-8 text-center text-sm text-muted-foreground"
            >
                Nothing recorded yet.
            </li>
            <li v-for="log in logs.data" :key="log.id">
                <details class="group">
                    <summary
                        class="flex cursor-pointer list-none flex-wrap items-center gap-x-4 gap-y-1 p-4 [&::-webkit-details-marker]:hidden"
                    >
                        <div class="min-w-0 flex-1 basis-60">
                            <p class="font-medium">
                                {{ log.actor ?? 'System' }}
                                <span
                                    class="font-normal text-muted-foreground"
                                    >{{
                                        labels[log.action] ?? log.action
                                    }}</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <span v-if="log.actor_role" class="capitalize"
                                    >{{ log.actor_role }} ·
                                </span>
                                <span class="font-mono">{{ log.action }}</span>
                            </p>
                        </div>
                        <time
                            :datetime="log.created_at"
                            class="tabular text-sm text-muted-foreground"
                            >{{ format.format(new Date(log.created_at)) }}</time
                        >
                        <ChevronDown
                            class="size-4 text-muted-foreground transition group-open:rotate-180"
                        />
                    </summary>
                    <div class="grid gap-2 px-4 pb-4 text-sm">
                        <table
                            v-if="changes(log).length"
                            class="w-full max-w-xl text-left"
                        >
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th class="py-1 pr-4 font-medium">Field</th>
                                    <th class="py-1 pr-4 font-medium">
                                        Before
                                    </th>
                                    <th class="py-1 font-medium">After</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="change in changes(log)"
                                    :key="change.key"
                                    class="border-t"
                                >
                                    <td class="py-1.5 pr-4 capitalize">
                                        {{ change.key }}
                                    </td>
                                    <td class="py-1.5 pr-4 text-loss">
                                        {{ show(change.from) }}
                                    </td>
                                    <td class="py-1.5 text-gain">
                                        {{ show(change.to) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-xs text-muted-foreground">
                            IP {{ log.ip_address ?? 'unknown' }}
                        </p>
                    </div>
                </details>
            </li>
        </ul>

        <nav
            v-if="logs.links.length > 3"
            class="flex flex-wrap gap-1"
            aria-label="Pages"
        >
            <template v-for="link in logs.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="min-w-9 rounded-md border px-3 py-1.5 text-center text-sm"
                    :class="
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-card'
                    "
                    >{{ pageLabel(link.label) }}</Link
                >
            </template>
        </nav>
    </div>
</template>
