/**
 * Preview of charges in the browser, mirroring App\Support\Charges\ChargeCalculator.
 * It makes the entry screen instant (and will work offline); the server always
 * recalculates when the transaction is saved, so this is never trusted for money.
 */

export type Tier = {
    min_amount: number;
    max_amount: number | null;
    flat: number;
    percent_bps: number;
    cap: number | null;
    step: number | null;
};

export type Rule = {
    transaction_type: string;
    kind: 'customer_charge' | 'provider_fee' | 'provider_commission';
    provider_id: string | null;
    outlet_id: string | null;
    collected_via: 'cash' | 'wallet';
    tiers: Tier[];
};

export type Quote = {
    charge: number;
    chargeCollectedVia: 'cash' | 'wallet';
    providerFee: number;
    commission: number;
    profit: number;
};

/** Integer division rounding half away from zero (same as the server). */
function roundedDiv(numerator: number, denominator: number): number {
    const quotient = Math.trunc(numerator / denominator);
    const remainder = numerator % denominator;

    if (Math.abs(remainder) * 2 >= denominator) {
        return quotient + (numerator < 0 ? -1 : 1);
    }

    return quotient;
}

function applyTier(tier: Tier, amount: number): number {
    const value =
        tier.step && tier.step > 0
            ? tier.flat * Math.ceil(amount / tier.step)
            : tier.flat + roundedDiv(amount * tier.percent_bps, 10_000);

    return tier.cap !== null ? Math.min(value, tier.cap) : value;
}

function applyRule(rule: Rule | undefined, amount: number): number {
    const tier = rule?.tiers.find(
        (t) =>
            amount >= t.min_amount &&
            (t.max_amount === null || amount <= t.max_amount),
    );

    return tier ? applyTier(tier, amount) : 0;
}

const specificity = (rule: Rule): number =>
    (rule.provider_id ? 2 : 0) + (rule.outlet_id ? 1 : 0);

export function quote(
    rules: Rule[],
    type: string,
    isService: boolean,
    providerId: string,
    outletId: string,
    amount: number,
): Quote {
    const matching = rules.filter(
        (r) =>
            r.transaction_type === type &&
            (r.provider_id === null || r.provider_id === providerId) &&
            (r.outlet_id === null || r.outlet_id === outletId),
    );

    const pick = (kind: Rule['kind']): Rule | undefined =>
        matching
            .filter((r) => r.kind === kind)
            .sort((a, b) => specificity(b) - specificity(a))[0];

    const chargeRule = pick('customer_charge');
    const charge = isService ? amount : applyRule(chargeRule, amount);
    const providerFee = applyRule(pick('provider_fee'), amount);
    const commission = applyRule(pick('provider_commission'), amount);

    return {
        charge,
        chargeCollectedVia: isService
            ? 'cash'
            : (chargeRule?.collected_via ?? 'cash'),
        providerFee,
        commission,
        profit: charge + commission - providerFee,
    };
}

/**
 * How the drawer and wallet will change, given the quote (same rules as TransactionPosting).
 */
export function effects(
    flow: string,
    amount: number,
    q: Quote,
): { cash: number; wallet: number } {
    const chargeToCash = q.chargeCollectedVia === 'cash' ? q.charge : 0;
    const chargeToWallet = q.charge - chargeToCash;
    const principal =
        flow === 'cash_out'
            ? { cash: -amount, wallet: amount }
            : flow === 'cash_in'
              ? { cash: amount, wallet: -amount }
              : { cash: 0, wallet: 0 };

    return {
        cash: principal.cash + chargeToCash,
        wallet:
            principal.wallet + chargeToWallet - q.providerFee + q.commission,
    };
}
