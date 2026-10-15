import { usePage } from '@inertiajs/vue3';

/**
 * Dates and times are always shown in the business's own time zone (Lagos by default),
 * never the device's: an owner checking from abroad sees the same times as the counter.
 */
export function useBusinessTime() {
    const page = usePage();
    const timeZone = page.props.tenant?.business.timezone ?? 'Africa/Lagos';

    const make = (options: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat('en-NG', { timeZone, ...options });

    const formats = {
        time: make({ hour: 'numeric', minute: '2-digit', hour12: true }),
        dayTime: make({
            day: 'numeric',
            month: 'short',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true,
        }),
        day: make({ day: 'numeric', month: 'short' }),
        full: make({ dateStyle: 'full', timeStyle: 'short' }),
        medium: make({ dateStyle: 'medium', timeStyle: 'short' }),
    };

    const format = (
        kind: keyof typeof formats,
        iso: string | null | undefined,
    ): string => (iso ? formats[kind].format(new Date(iso)) : '—');

    /** Today's date in the business's time zone, as YYYY-MM-DD. */
    const today = (): string =>
        new Intl.DateTimeFormat('en-CA', { timeZone }).format(new Date());

    return { timeZone, format, today };
}
