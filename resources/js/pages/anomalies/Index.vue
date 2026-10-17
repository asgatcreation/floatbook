<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    Eye,
    ShieldCheck,
    TriangleAlert,
    Undo2,
} from '@lucide/vue';
import { ref } from 'vue';
import AnomalyController from '@/actions/App/Http/Controllers/AnomalyController';
import PageHeader from '@/components/PageHeader.vue';
import StatusPill from '@/components/StatusPill.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useBusinessTime } from '@/lib/dates';
import { index } from '@/routes/flags';
import { show as showTransaction } from '@/routes/transactions';

type Flag = {
    id: string;
    kind: string;
    kind_label: string;
    hint: string;
    severity: 'watch' | 'serious';
    summary: string;
    details: Record<string, unknown>;
    outlet: string | null;
    person: string | null;
    subject_type: string | null;
    subject_id: string | null;
    status: 'open' | 'dismissed' | 'confirmed';
    reviewed_by: string | null;
    review_note: string | null;
    created_at: string | null;
};

defineOptions({
    layout: { breadcrumbs: [{ title: 'Flags', href: index() }] },
});

defineProps<{
    flags: {
        data: Flag[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    status: 'open' | 'dismissed' | 'confirmed';
    counts: { open: number; dismissed: number; confirmed: number };
    kinds: { value: string; label: string }[];
    canReview: boolean;
}>();

const { format } = useBusinessTime();

// The note is optional; it is only kept for the flag being judged right now.
const noteFor = ref<string | null>(null);
const note = ref('');

function review(flag: Flag, status: 'dismissed' | 'confirmed' | 'open'): void {
    router.post(
        AnomalyController.review.url(flag.id),
        { status, note: noteFor.value === flag.id ? note.value : null },
        {
            preserveScroll: true,
            onSuccess: () => {
                noteFor.value = null;
                note.value = '';
            },
        },
    );
}

const tabs = [
    { value: 'open', label: 'To look at' },
    { value: 'confirmed', label: 'Real problems' },
    { value: 'dismissed', label: 'Were fine' },
] as const;
</script>

<template>
    <Head title="Flags" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Flags"
            description="Entries worth a second look. A flag is a question, not an accusation: you decide what it means."
        />

        <nav class="flex flex-wrap gap-1.5" aria-label="Which flags">
            <Button
                v-for="tab in tabs"
                :key="tab.value"
                size="sm"
                :variant="status === tab.value ? 'default' : 'outline'"
                @click="
                    router.get(
                        index.url(),
                        { status: tab.value },
                        { preserveScroll: true },
                    )
                "
            >
                {{ tab.label }}
                <span class="tabular opacity-70">{{ counts[tab.value] }}</span>
            </Button>
        </nav>

        <section
            v-if="flags.data.length === 0"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-14 text-center"
        >
            <span
                class="grid size-12 place-items-center rounded-full bg-gain-soft text-gain"
            >
                <ShieldCheck class="size-6" />
            </span>
            <h2 class="text-lg font-semibold">
                {{ status === 'open' ? 'Nothing to look at' : 'Nothing here' }}
            </h2>
            <p class="max-w-sm text-sm text-muted-foreground">
                FloatBook watches for the same reference twice, unusually large
                amounts, entries after hours, frequent voids and outlets that
                keep coming up short.
            </p>
        </section>

        <ul v-else class="grid gap-3">
            <li
                v-for="flag in flags.data"
                :key="flag.id"
                class="rounded-2xl border bg-card p-4"
                :class="{
                    'border-loss/40':
                        flag.severity === 'serious' && flag.status === 'open',
                }"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-xl"
                        :class="
                            flag.status !== 'open'
                                ? 'bg-muted text-muted-foreground'
                                : flag.severity === 'serious'
                                  ? 'bg-loss-soft text-loss'
                                  : 'bg-warning-soft text-warning'
                        "
                    >
                        <component
                            :is="
                                flag.severity === 'serious'
                                    ? TriangleAlert
                                    : CircleAlert
                            "
                            class="size-5"
                            aria-hidden="true"
                        />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold">{{ flag.kind_label }}</h2>
                            <StatusPill
                                v-if="flag.status === 'confirmed'"
                                tone="loss"
                                >Real problem</StatusPill
                            >
                            <StatusPill v-else-if="flag.status === 'dismissed'"
                                >Was fine</StatusPill
                            >
                        </div>
                        <p class="mt-0.5">{{ flag.summary }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ flag.hint }}
                        </p>
                        <p class="mt-1.5 text-xs text-muted-foreground">
                            <template v-if="flag.outlet"
                                >{{ flag.outlet }} ·
                            </template>
                            <template v-if="flag.person"
                                >{{ flag.person }} ·
                            </template>
                            {{ format('dayTime', flag.created_at) }}
                        </p>
                        <p v-if="flag.review_note" class="mt-1.5 text-sm">
                            <span class="text-muted-foreground"
                                >{{ flag.reviewed_by }} said:</span
                            >
                            {{ flag.review_note }}
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <Button
                                v-if="
                                    flag.subject_type === 'Transaction' &&
                                    flag.subject_id
                                "
                                as-child
                                size="sm"
                                variant="outline"
                            >
                                <Link :href="showTransaction(flag.subject_id)">
                                    <Eye class="size-4" /> Open the entry
                                </Link>
                            </Button>

                            <template
                                v-if="canReview && flag.status === 'open'"
                            >
                                <Input
                                    v-if="noteFor === flag.id"
                                    v-model="note"
                                    class="h-9 w-full sm:w-64"
                                    placeholder="What did you find? (optional)"
                                    maxlength="300"
                                />
                                <Button
                                    v-else
                                    size="sm"
                                    variant="ghost"
                                    class="text-muted-foreground"
                                    @click="((noteFor = flag.id), (note = ''))"
                                >
                                    Add a note
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    @click="review(flag, 'dismissed')"
                                >
                                    <CircleCheck class="size-4" /> It was fine
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    class="text-loss"
                                    @click="review(flag, 'confirmed')"
                                >
                                    <TriangleAlert class="size-4" /> It was a
                                    problem
                                </Button>
                            </template>

                            <Button
                                v-else-if="canReview"
                                size="sm"
                                variant="ghost"
                                class="text-muted-foreground"
                                @click="review(flag, 'open')"
                            >
                                <Undo2 class="size-4" /> Look at it again
                            </Button>
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <nav
            v-if="flags.links.length > 3"
            class="flex flex-wrap gap-1"
            aria-label="Pages"
        >
            <Button
                v-for="(link, i) in flags.links"
                :key="i"
                size="sm"
                :variant="link.active ? 'default' : 'ghost'"
                :disabled="!link.url"
                @click="link.url && router.get(link.url)"
            >
                <span v-html="link.label" />
            </Button>
        </nav>
    </div>
</template>
