/**
 * Transactions waiting on this phone to be sent to the server.
 *
 * Every entry is saved here first and then sent. With a connection that happens at
 * once; without one it waits and is retried when the network returns. Each entry keeps
 * its idempotency key, so sending it twice can never create two transactions: the
 * server answers "already saved" the second time.
 *
 * Entries remember who made them, so on a shared phone one person's queue is never
 * sent under someone else's account.
 */

export type QueuedEntry = {
    idempotency_key: string;
    user_id: number;
    business_id: string;
    payload: Record<string, unknown>;
    summary: string; // e.g. "₦10,000 withdrawal", shown in the sync panel
    /** How this entry changes the drawer and wallet, so balances stay right while it waits. */
    effect: {
        outlet_id: string;
        provider_id: string;
        cash: number;
        wallet: number;
    } | null;
    created_at: string;
    attempts: number;
    error: string | null; // set when the server refused the entry (needs a person to look)
};

const DB_NAME = 'floatbook';
const STORE = 'queue';

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore(STORE, {
                keyPath: 'idempotency_key',
            });
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function run<T>(
    mode: IDBTransactionMode,
    action: (store: IDBObjectStore) => IDBRequest<T>,
): Promise<T> {
    const db = await open();

    return new Promise<T>((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const request = action(tx.objectStore(STORE));
        tx.oncomplete = () => {
            db.close();
            resolve(request.result);
        };
        tx.onerror = () => reject(tx.error);
    });
}

export function put(entry: QueuedEntry): Promise<IDBValidKey> {
    return run('readwrite', (s) => s.put(entry));
}

export function remove(key: string): Promise<undefined> {
    return run('readwrite', (s) => s.delete(key));
}

export async function all(): Promise<QueuedEntry[]> {
    const entries = await run(
        'readonly',
        (s) => s.getAll() as IDBRequest<QueuedEntry[]>,
    );

    return entries.sort((a, b) => a.created_at.localeCompare(b.created_at));
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export type SendResult =
    | { ok: true; duplicate: boolean }
    | {
          ok: false;
          reason: 'offline' | 'signed_out' | 'locked' | 'rejected' | 'server';
          message: string;
          errors?: Record<string, string>;
      };

/**
 * Send one entry. Never throws: the caller decides what to do from the result.
 */
export async function send(
    entry: QueuedEntry,
    url: string,
): Promise<SendResult> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify(entry.payload),
        });
    } catch {
        return { ok: false, reason: 'offline', message: 'No connection.' };
    }

    if (response.ok) {
        const body = (await response.json().catch(() => ({}))) as {
            duplicate?: boolean;
        };

        return { ok: true, duplicate: Boolean(body.duplicate) };
    }

    if (response.status === 401 || response.status === 419) {
        return {
            ok: false,
            reason: 'signed_out',
            message: 'Sign in again to send saved entries.',
        };
    }

    if (response.status === 423) {
        return {
            ok: false,
            reason: 'locked',
            message: 'Unlock the screen to send saved entries.',
        };
    }

    if (response.status === 422 || response.status === 403) {
        const body = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const errors = Object.fromEntries(
            Object.entries(body.errors ?? {}).map(([k, v]) => [k, v[0] ?? '']),
        );

        return {
            ok: false,
            reason: 'rejected',
            message:
                Object.values(errors)[0] ??
                body.message ??
                'The server refused this entry.',
            errors,
        };
    }

    return {
        ok: false,
        reason: 'server',
        message: 'The server had a problem. It will try again.',
    };
}
