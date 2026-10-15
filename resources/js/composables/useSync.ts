import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import TransactionController from '@/actions/App/Http/Controllers/TransactionController';
import * as queue from '@/lib/offlineQueue';
import type { QueuedEntry, SendResult } from '@/lib/offlineQueue';

/** Entries waiting longer than this were made offline, so the server keeps their original time. */
const OFFLINE_AFTER_MS = 60_000;

const state = reactive({
    entries: [] as QueuedEntry[],
    online: typeof navigator === 'undefined' ? true : navigator.onLine,
    syncing: false,
    blocked: null as null | 'signed_out' | 'locked',
    blockedMessage: '',
});

let started = false;
const syncedListeners = new Set<() => void>();

function currentOwner(): { userId: number; businessId: string } | null {
    const page = usePage();
    const user = page.props.auth?.user;
    const business = page.props.tenant?.business;

    return user && business
        ? { userId: user.id, businessId: business.id }
        : null;
}

async function refresh(): Promise<void> {
    const owner = currentOwner();

    try {
        const entries = await queue.all();
        state.entries = owner
            ? entries.filter(
                  (e) =>
                      e.user_id === owner.userId &&
                      e.business_id === owner.businessId,
              )
            : [];
    } catch {
        state.entries = [];
    }
}

function payloadFor(entry: QueuedEntry): QueuedEntry {
    const age = Date.now() - new Date(entry.created_at).getTime();

    return {
        ...entry,
        payload: {
            ...entry.payload,
            source: age > OFFLINE_AFTER_MS ? 'offline_sync' : 'counter',
        },
    };
}

/**
 * Send everything waiting, oldest first. Stops at the first sign the network or the
 * session isn't available; entries the server refuses are kept with their reason.
 */
async function sync(): Promise<void> {
    if (state.syncing) {
        return;
    }

    state.syncing = true;
    let sent = 0;

    try {
        await refresh();

        for (const entry of state.entries.filter((e) => !e.error)) {
            const result = await queue.send(
                payloadFor(entry),
                TransactionController.store.url(),
            );

            if (result.ok) {
                await queue.remove(entry.idempotency_key);
                sent++;
                state.blocked = null;
                continue;
            }

            if (result.reason === 'rejected') {
                await queue.put({
                    ...entry,
                    attempts: entry.attempts + 1,
                    error: result.message,
                });
                continue;
            }

            if (result.reason === 'signed_out' || result.reason === 'locked') {
                state.blocked = result.reason;
                state.blockedMessage = result.message;
            }

            break; // offline or server trouble: try again later
        }
    } finally {
        await refresh();
        state.syncing = false;
    }

    if (sent > 0) {
        syncedListeners.forEach((listener) => listener());
    }
}

/**
 * Save a new entry: onto the phone first, then straight to the server when possible.
 * Returns the server's answer for this entry, or "queued" if it is waiting for a connection.
 */
async function save(
    payload: Record<string, unknown>,
    summary: string,
    effect: QueuedEntry['effect'] = null,
): Promise<SendResult | { ok: 'queued' }> {
    const owner = currentOwner();

    if (!owner) {
        return {
            ok: false,
            reason: 'signed_out',
            message: 'Sign in again to save.',
        };
    }

    const entry: QueuedEntry = {
        idempotency_key: String(payload.idempotency_key),
        user_id: owner.userId,
        business_id: owner.businessId,
        payload,
        summary,
        effect,
        created_at: new Date().toISOString(),
        attempts: 0,
        error: null,
    };

    await queue.put(entry);

    if (!navigator.onLine) {
        await refresh();

        return { ok: 'queued' };
    }

    const result = await queue.send(
        payloadFor(entry),
        TransactionController.store.url(),
    );

    if (result.ok) {
        await queue.remove(entry.idempotency_key);
    } else if (result.reason === 'rejected') {
        // A person is right there to fix it, so don't keep a refused entry around.
        await queue.remove(entry.idempotency_key);
    }

    await refresh();

    if (!result.ok && result.reason !== 'rejected') {
        return { ok: 'queued' };
    }

    return result;
}

async function discard(key: string): Promise<void> {
    await queue.remove(key);
    await refresh();
}

/**
 * Start listening for the connection coming back. Safe to call more than once.
 */
function start(): void {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;

    window.addEventListener('online', () => {
        state.online = true;
        void sync();
    });
    window.addEventListener('offline', () => (state.online = false));
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            void sync();
        }
    });
    router.on('navigate', () => void refresh());
    setInterval(() => {
        if (navigator.onLine && state.entries.length) {
            void sync();
        }
    }, 30_000);

    void sync();
}

export function useSync() {
    return {
        state,
        waiting: computed(() => state.entries.filter((e) => !e.error)),
        refused: computed(() => state.entries.filter((e) => e.error)),
        save,
        sync,
        discard,
        start,
        onSynced(listener: () => void): () => void {
            syncedListeners.add(listener);

            return () => syncedListeners.delete(listener);
        },
    };
}
