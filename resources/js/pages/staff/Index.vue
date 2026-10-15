<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import {
    KeyRound,
    MailPlus,
    Pencil,
    RotateCcw,
    ShieldCheck,
    UserMinus,
    UserPlus,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InvitationController from '@/actions/App/Http/Controllers/InvitationController';
import StaffController from '@/actions/App/Http/Controllers/StaffController';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/composables/useInitials';
import { index } from '@/routes/staff';
import type { OutletRef, RoleOption, RoleValue } from '@/types';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
type Member = {
    id: string;
    name: string;
    email: string;
    role: RoleValue;
    role_label: string;
    status: 'active' | 'suspended';
    two_factor: boolean;
    has_pin: boolean;
    last_active_at: string | null;
    outlets: OutletRef[];
    is_me: boolean;
    can_manage: boolean;
};

type PendingInvitation = {
    id: string;
    email: string;
    role_label: string;
    expires_at: string;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Staff', href: index() }] },
});

const props = defineProps<{
    members: Member[];
    invitations: PendingInvitation[];
    outlets: OutletRef[];
    roles: RoleOption[];
}>();

const { getInitials } = useInitials();

// One dialog handles both inviting and editing.
const dialogOpen = ref(false);
const editing = ref<Member | null>(null);
const selectedRole = ref<RoleValue>('agent');
const selectedOutlets = ref<string[]>([]);

const selectedRoleOption = computed(() =>
    props.roles.find((role) => role.value === selectedRole.value),
);

function openInvite(): void {
    editing.value = null;
    // Agents are invited most often, so start there when allowed.
    selectedRole.value = props.roles.some((r) => r.value === 'agent')
        ? 'agent'
        : (props.roles[0]?.value ?? 'agent');
    selectedOutlets.value =
        props.outlets.length === 1 ? [props.outlets[0].id] : [];
    dialogOpen.value = true;
}

function openEdit(member: Member): void {
    editing.value = member;
    selectedRole.value = member.role;
    selectedOutlets.value = member.outlets.map((outlet) => outlet.id);
    dialogOpen.value = true;
}

function suspend(member: Member): void {
    if (
        window.confirm(
            `Remove ${member.name}'s access? They will be signed out straight away.`,
        )
    ) {
        router.post(
            StaffController.suspend.url(member.id),
            {},
            { preserveScroll: true },
        );
    }
}

function reactivate(member: Member): void {
    router.post(
        StaffController.reactivate.url(member.id),
        {},
        { preserveScroll: true },
    );
}

function resend(invitation: PendingInvitation): void {
    router.post(
        InvitationController.resend.url(invitation.id),
        {},
        { preserveScroll: true },
    );
}

function cancel(invitation: PendingInvitation): void {
    if (window.confirm(`Cancel the invitation for ${invitation.email}?`)) {
        router.delete(InvitationController.destroy.url(invitation.id), {
            preserveScroll: true,
        });
    }
}

const dateFormat = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    day: 'numeric',
    month: 'short',
});
const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

function lastSeen(iso: string | null): string {
    if (!iso) {
        return 'Never signed in';
    }

    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);

    if (minutes < 60) {
        return `Active ${relative.format(-Math.max(minutes, 1), 'minute')}`;
    }

    if (minutes < 60 * 24) {
        return `Active ${relative.format(-Math.round(minutes / 60), 'hour')}`;
    }

    return `Active ${relative.format(-Math.round(minutes / 1440), 'day')}`;
}
</script>

<template>
    <Head title="Staff" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Staff"
            description="Who can use FloatBook, what they can do, and which outlets they cover."
        >
            <template #actions>
                <Button data-test="invite-staff" @click="openInvite">
                    <UserPlus class="size-4" /> Invite staff
                </Button>
            </template>
        </PageHeader>

        <section aria-label="Team members" class="rounded-2xl border bg-card">
            <ul class="divide-y">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-3 p-4"
                    :class="{ 'opacity-60': member.status === 'suspended' }"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-full bg-muted text-sm font-semibold"
                        aria-hidden="true"
                        >{{ getInitials(member.name) }}</span
                    >
                    <div class="min-w-0 flex-1 basis-48">
                        <p class="truncate font-semibold">
                            {{ member.name }}
                            <span
                                v-if="member.is_me"
                                class="font-normal text-muted-foreground"
                                >(you)</span
                            >
                        </p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ member.email }}
                        </p>
                    </div>
                    <div class="flex min-w-0 basis-56 flex-col gap-1 text-sm">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <StatusPill
                                :tone="
                                    member.role === 'owner'
                                        ? 'warning'
                                        : 'neutral'
                                "
                                >{{ member.role_label }}</StatusPill
                            >
                            <StatusPill
                                v-if="member.status === 'suspended'"
                                tone="loss"
                                >No access</StatusPill
                            >
                            <span
                                v-if="member.two_factor"
                                class="inline-flex items-center gap-1 text-xs text-gain"
                                title="Two-factor authentication is on"
                            >
                                <ShieldCheck class="size-3.5" /> 2FA
                            </span>
                            <span
                                v-if="member.has_pin"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                title="Has a quick-unlock PIN"
                            >
                                <KeyRound class="size-3.5" /> PIN
                            </span>
                        </div>
                        <p class="truncate text-muted-foreground">
                            {{
                                member.outlets.length
                                    ? member.outlets
                                          .map((o) => o.name)
                                          .join(', ')
                                    : 'All outlets'
                            }}
                            · {{ lastSeen(member.last_active_at) }}
                        </p>
                    </div>
                    <div
                        v-if="member.can_manage"
                        class="ml-auto flex shrink-0 gap-2"
                    >
                        <Button
                            variant="outline"
                            size="sm"
                            :aria-label="`Edit ${member.name}`"
                            @click="openEdit(member)"
                        >
                            <Pencil class="size-4" />
                            <span class="hidden sm:inline">Edit</span>
                        </Button>
                        <Button
                            v-if="member.status === 'active'"
                            variant="outline"
                            size="sm"
                            class="text-loss"
                            :aria-label="`Remove access for ${member.name}`"
                            @click="suspend(member)"
                        >
                            <UserMinus class="size-4" />
                            <span class="hidden sm:inline">Remove access</span>
                        </Button>
                        <Button
                            v-else
                            variant="outline"
                            size="sm"
                            @click="reactivate(member)"
                        >
                            <RotateCcw class="size-4" /> Restore access
                        </Button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="invitations.length" aria-labelledby="pending-heading">
            <h2 id="pending-heading" class="mb-3 text-base font-semibold">
                Pending invitations
            </h2>
            <ul class="divide-y rounded-2xl border bg-card">
                <li
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex flex-wrap items-center gap-3 p-4"
                >
                    <MailPlus class="size-5 text-muted-foreground" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">
                            {{ invitation.email }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ invitation.role_label }} · expires
                            {{
                                dateFormat.format(
                                    new Date(invitation.expires_at),
                                )
                            }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="resend(invitation)"
                            >Resend</Button
                        >
                        <Button
                            variant="ghost"
                            size="sm"
                            :aria-label="`Cancel invitation for ${invitation.email}`"
                            @click="cancel(invitation)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-lg">
            <Form
                :key="editing?.id ?? 'invite'"
                v-bind="
                    editing
                        ? StaffController.update.form(editing.id)
                        : InvitationController.store.form()
                "
                :options="{ preserveScroll: true }"
                class="grid gap-5"
                @success="dialogOpen = false"
                #default="{ errors, processing }"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        editing
                            ? `Change ${editing.name}'s access`
                            : 'Invite staff'
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            editing
                                ? 'Changes apply the next time they open a page.'
                                : "They'll get an email with a link to join. The link works for 7 days."
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div v-if="!editing" class="grid gap-2">
                    <Label for="invite-email">Email address</Label>
                    <Input
                        id="invite-email"
                        name="email"
                        type="email"
                        autocomplete="off"
                        placeholder="name@example.com"
                        required
                    />
                    <InputError :message="errors.email" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Role</legend>
                    <label
                        v-for="role in roles"
                        :key="role.value"
                        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted"
                    >
                        <input
                            v-model="selectedRole"
                            type="radio"
                            name="role"
                            :value="role.value"
                            class="mt-1 accent-[var(--primary)]"
                        />
                        <span>
                            <span class="font-semibold">{{ role.label }}</span>
                            <span class="block text-muted-foreground">{{
                                role.description
                            }}</span>
                        </span>
                    </label>
                    <InputError :message="errors.role" />
                </fieldset>

                <fieldset
                    v-if="selectedRoleOption?.outlet_scoped"
                    class="grid gap-2"
                >
                    <legend class="mb-2 text-sm font-medium">Outlets</legend>
                    <p
                        v-if="outlets.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Add an outlet first.
                    </p>
                    <label
                        v-for="outlet in outlets"
                        :key="outlet.id"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm"
                    >
                        <input
                            v-model="selectedOutlets"
                            type="checkbox"
                            name="outlet_ids[]"
                            :value="outlet.id"
                            class="size-4 accent-[var(--primary)]"
                        />
                        {{ outlet.name }}
                    </label>
                    <InputError
                        :message="errors.outlet_ids ?? errors['outlet_ids.0']"
                    />
                </fieldset>

                <DialogFooter>
                    <Button type="submit" :disabled="processing">
                        <Spinner v-if="processing" />
                        {{ editing ? 'Save changes' : 'Send invitation' }}
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
