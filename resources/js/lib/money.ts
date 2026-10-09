/**
 * Money in the browser, mirroring App\Support\Money\Money on the server.
 * Amounts are always integer kobo (₦1 = 100 kobo); never floating-point naira.
 */

const grouping = new Intl.NumberFormat('en-NG');

export type FormatOptions = {
    /** Hide ".00" when there are no kobo. */
    trim?: boolean;
    /** Show a leading + for positive amounts (for changes, not balances). */
    signed?: boolean;
};

export function formatNaira(kobo: number, options: FormatOptions = {}): string {
    const abs = Math.abs(Math.trunc(kobo));
    const naira = grouping.format(Math.floor(abs / 100));
    const koboPart = String(abs % 100).padStart(2, '0');
    const body =
        options.trim && koboPart === '00'
            ? `₦${naira}`
            : `₦${naira}.${koboPart}`;

    if (kobo < 0) {
        return `−${body}`;
    }

    return options.signed && kobo > 0 ? `+${body}` : body;
}

/**
 * Split for display: "₦12,500" and ".50", so the kobo can be shown smaller.
 */
export function splitNaira(kobo: number): {
    sign: string;
    naira: string;
    kobo: string;
} {
    const abs = Math.abs(Math.trunc(kobo));

    return {
        sign: kobo < 0 ? '−' : '',
        naira: `₦${grouping.format(Math.floor(abs / 100))}`,
        kobo: `.${String(abs % 100).padStart(2, '0')}`,
    };
}

/**
 * Parse what someone typed ("5,000", "₦5000.50") into kobo, or null if it isn't a valid amount.
 */
export function parseNaira(input: string): number | null {
    const clean = input.replace(/[₦,\s]/g, '');
    const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(clean);

    if (!match) {
        return null;
    }

    return Number(match[1]) * 100 + Number((match[2] ?? '0').padEnd(2, '0'));
}

/** Kobo to a plain naira string for inputs ("12500.5" → "12500.50"). */
export function koboToInput(kobo: number | null | undefined): string {
    if (kobo === null || kobo === undefined) {
        return '';
    }

    const naira = Math.floor(kobo / 100);
    const rest = kobo % 100;

    return rest === 0
        ? String(naira)
        : `${naira}.${String(rest).padStart(2, '0')}`;
}
