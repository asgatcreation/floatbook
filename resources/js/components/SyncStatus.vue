<script setup lang="ts">
import {
    CloudOff,
    CloudUpload,
    RefreshCw,
    TriangleAlert,
    Wifi,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSync } from '@/composables/useSync';

import { useBusinessTime } from '@/lib/dates';

const { timeZone } = useBusinessTime();
/**
 * A small pill in the header: "Offline", "3 waiting to sync", or nothing when all is sent.
 * Opens a panel listing what is waiting and anything the server refused.
 */
const { state, waiting, refused, sync, discard, start } = useSync();
const open = ref(false);

onMounted(start);

const visible = computed(
    () => !state.online || state.entries.length > 0 || state.blocked !== null,
);
const label = computed(() => {
    if (refused.value.length) {
        return `${refused.value.length} need${refused.value.length === 1 ? 's' : ''} attention`;
    }

    if (waiting.value.length) {
        return `${waiting.value.length} waiting to sync`;
    }

    return state.online ? 'All synced' : 'Offline';
});

const time = new Intl.DateTimeFormat('en-NG', {
    timeZone,
    hour: 'numeric',
    minute: '2-digit',
    hour12: true,
});
</script>

<template>
    <DropdownMenu v-if="visible" v-model:open="open">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="inline-flex min-h-9 items-center gap-1.5 rounded-full px-3 text-xs font-semibold"
                :class="
                    refused.length
                        ? 'bg-loss-soft text-loss'
                        : waiting.length || !state.online
                          ? 'bg-warning-soft text-warning'
                          : 'bg-gain-soft text-gain'
                "
                data-test="sync-status"
            >
                <TriangleAlert v-if="refused.length" class="size-3.5" />
                <CloudOff v-else-if="!state.online" class="size-3.5" />
                <RefreshCw
                    v-else-if="state.syncing"
                    class="size-3.5 animate-spin"
                />
                <CloudUpload v-else class="size-3.5" />
                {{ label }}
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80 p-3">
            <div class="grid gap-3 text-sm">
                <p class="flex items-center gap-2 font-semibold">
                    <Wifi v-if="state.online" class="size-4 text-gain" />
                    <CloudOff v-else class="size-4 text-warning" />
                    {{ state.online ? 'Connected' : 'No connection' }}
                </p>
                <p class="text-muted-foreground">
                    Entries are saved on this phone first and sent as soon as
                    there is a connection. Nothing is ever sent twice.
                </p>
                <p
                    v-if="state.blocked"
                    class="rounded-lg bg-warning-soft p-2 text-warning"
                >
                    {{ state.blockedMessage }}
                </p>

                <ul
                    v-if="state.entries.length"
                    class="grid max-h-60 gap-1.5 overflow-y-auto"
                >
                    <li
                        v-for="e in state.entries"
                        :key="e.idempotency_key"
                        class="rounded-lg border p-2"
                        :class="{ 'border-loss/40 bg-loss-soft/40': e.error }"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ e.summary }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                time.format(new Date(e.created_at))
                            }}</span>
                        </div>
                        <template v-if="e.error">
                            <p class="mt-1 text-xs text-loss">{{ e.error }}</p>
                            <button
                                type="button"
                                class="mt-1 text-xs underline"
                                @click="discard(e.idempotency_key)"
                            >
                                Discard this entry
                            </button>
                        </template>
                    </li>
                </ul>

                <Button
                    v-if="waiting.length"
                    size="sm"
                    :disabled="state.syncing || !state.online"
                    @click="sync"
                >
                    <RefreshCw
                        class="size-4"
                        :class="{ 'animate-spin': state.syncing }"
                    />
                    Sync now
                </Button>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
