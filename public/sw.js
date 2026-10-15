/*
 * FloatBook service worker.
 *
 * Keeps the agent entry screen usable without a network:
 *  - built assets (hashed, never change) and fonts: cache first
 *  - the entry screen page: network first, falling back to the last copy saved on the phone
 *  - any other page while offline: a friendly offline page
 * Data requests (Inertia visits, saves) are never cached here; saving offline is handled
 * by the app's own queue in IndexedDB, which syncs when the connection returns.
 */
const VERSION = 'floatbook-v1';
const STATIC_CACHE = `${VERSION}-static`;
const PAGE_CACHE = `${VERSION}-pages`;
const OFFLINE_PAGES = ['/transactions/new'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(STATIC_CACHE)
            .then((cache) =>
                cache.addAll([
                    '/offline.html',
                    '/favicon.svg',
                    '/icons/icon-192.png',
                    '/manifest.webmanifest',
                ]),
            ),
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((k) => !k.startsWith(VERSION))
                        .map((k) => caches.delete(k)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

// Signing out clears saved pages, so the next person on a shared phone sees nothing.
self.addEventListener('message', (event) => {
    if (event.data === 'clear-pages') {
        event.waitUntil(caches.delete(PAGE_CACHE));
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (
        url.pathname.startsWith('/build/') ||
        url.pathname.startsWith('/icons/') ||
        url.pathname.startsWith('/fonts/')
    ) {
        event.respondWith(cacheFirst(request));

        return;
    }

    // Only full page loads; Inertia's own requests carry X-Inertia and must reach the server.
    if (request.mode === 'navigate') {
        event.respondWith(
            OFFLINE_PAGES.includes(url.pathname)
                ? networkFirst(request)
                : networkOrOfflinePage(request),
        );
    }
});

async function cacheFirst(request) {
    const cached = await caches.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok) {
        const cache = await caches.open(STATIC_CACHE);
        cache.put(request, response.clone());
    }

    return response;
}

async function networkFirst(request) {
    try {
        const response = await fetch(request);

        // Only keep real pages (not redirects to the login screen).
        if (response.ok && !response.redirected) {
            const cache = await caches.open(PAGE_CACHE);
            cache.put(request, response.clone());
        }

        return response;
    } catch {
        return (
            (await caches.match(request, { cacheName: PAGE_CACHE })) ??
            caches.match('/offline.html')
        );
    }
}

async function networkOrOfflinePage(request) {
    try {
        return await fetch(request);
    } catch {
        return caches.match('/offline.html');
    }
}
