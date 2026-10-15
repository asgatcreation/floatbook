/**
 * Registers the service worker (see public/sw.js) in production builds, so the agent
 * entry screen opens without a network. Skipped during development, where caching
 * would get in the way of live reloading.
 */
export function registerServiceWorker(): void {
    if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Not fatal: the app works online without it.
        });
    });
}

/**
 * Forget saved pages on sign-out, so the next person on a shared phone can't open them.
 */
export function clearSavedPages(): void {
    navigator.serviceWorker?.controller?.postMessage('clear-pages');
}
