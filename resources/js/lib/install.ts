import { ref } from 'vue';

/**
 * "Add FloatBook to the home screen". Chrome and Android fire beforeinstallprompt once,
 * often before the page has drawn, so it is caught here as soon as the app loads and
 * kept until someone presses Install. iPhones have no such event; there we can only
 * explain the Share → Add to Home Screen steps.
 */
type InstallPromptEvent = Event & {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

const DISMISSED_KEY = 'floatbook.install-dismissed';

export const installEvent = ref<InstallPromptEvent | null>(null);

export function listenForInstall(): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installEvent.value = event as InstallPromptEvent;
    });
    window.addEventListener('appinstalled', () => (installEvent.value = null));
}

export function isInstalled(): boolean {
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true
    );
}

export function isIos(): boolean {
    return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

export async function install(): Promise<boolean> {
    const event = installEvent.value;

    if (!event) {
        return false;
    }

    await event.prompt();
    const { outcome } = await event.userChoice;
    installEvent.value = null;

    return outcome === 'accepted';
}

export function wasDismissed(): boolean {
    try {
        return localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        return false;
    }
}

export function rememberDismissed(): void {
    try {
        localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
        // Private mode: the card simply shows again next time.
    }
}
