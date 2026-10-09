import type { Tier } from '@/lib/charges';
import { formatNaira } from '@/lib/money';

const naira = (kobo: number) => formatNaira(kobo, { trim: true });
const percent = (bps: number) =>
    `${(bps / 100).toLocaleString('en-NG', { maximumFractionDigits: 2 })}%`;

/** One band in plain words, e.g. "₦100 per ₦5,000" or "0.5%, at most ₦100". */
export function describeTier(tier: Tier): string {
    if (tier.step) {
        return `${naira(tier.flat)} per ${naira(tier.step)}`;
    }

    const parts: string[] = [];

    if (tier.flat) {
        parts.push(naira(tier.flat));
    }

    if (tier.percent_bps) {
        parts.push(percent(tier.percent_bps));
    }

    let text = parts.length ? parts.join(' + ') : 'Nothing';

    if (tier.cap !== null) {
        text += `, at most ${naira(tier.cap)}`;
    }

    return text;
}

/**
 * The amount range a band covers in plain words: "Up to ₦10,000", "Over ₦10,000 up to ₦50,000",
 * "Over ₦50,000". A band starting one kobo above a round number reads as "Over" that number.
 */
export function describeRange(tier: Tier, isOnly: boolean): string {
    if (isOnly && tier.min_amount === 0 && tier.max_amount === null) {
        return 'Any amount';
    }

    const start =
        tier.min_amount % 100 === 1
            ? `Over ${naira(tier.min_amount - 1)}`
            : tier.min_amount <= 100
              ? null
              : `From ${naira(tier.min_amount)}`;

    if (tier.max_amount === null) {
        return start ?? 'Any amount';
    }

    return start
        ? `${start} up to ${naira(tier.max_amount)}`
        : `Up to ${naira(tier.max_amount)}`;
}
