<?php

namespace App\Support\Charges;

use App\Enums\ChargeKind;
use App\Enums\TransactionType;
use App\Models\ChargeRule;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Works out the customer charge, provider fee and provider commission for a transaction,
 * using the most specific active rule for each (outlet + provider > provider > outlet > all).
 *
 * For services (BVN/NIN, card issuance...) the amount IS the price the customer pays,
 * so the whole amount is the charge.
 */
class ChargeCalculator
{
    /** @var array<string, Collection<int, ChargeRule>> */
    private array $rulesByType = [];

    public function quote(TransactionType $type, string $providerId, string $outletId, Money $amount): ChargeQuote
    {
        $rules = $this->rulesFor($type)->filter(fn (ChargeRule $rule) => ($rule->provider_id === null || $rule->provider_id === $providerId)
            && ($rule->outlet_id === null || $rule->outlet_id === $outletId));

        $pick = fn (ChargeKind $kind): ?ChargeRule => $rules
            ->filter(fn (ChargeRule $rule) => $rule->kind === $kind)
            ->sortByDesc(fn (ChargeRule $rule) => $rule->specificity())
            ->first();

        $chargeRule = $pick(ChargeKind::CustomerCharge);

        $charge = $type->isService() ? $amount : $this->apply($chargeRule, $amount);
        $collectedVia = $type->isService() ? 'cash' : ($chargeRule->collected_via ?? 'cash');

        return new ChargeQuote(
            charge: $charge,
            chargeCollectedVia: $collectedVia,
            providerFee: $this->apply($pick(ChargeKind::ProviderFee), $amount),
            commission: $this->apply($pick(ChargeKind::ProviderCommission), $amount),
        );
    }

    private function apply(?ChargeRule $rule, Money $amount): Money
    {
        $tier = $rule?->tiers->first(fn ($tier) => $tier->covers($amount));

        return $tier !== null ? $tier->apply($amount) : Money::zero();
    }

    /**
     * @return Collection<int, ChargeRule>
     */
    private function rulesFor(TransactionType $type): Collection
    {
        return $this->rulesByType[$type->value] ??= ChargeRule::query()
            ->with('tiers')
            ->where('transaction_type', $type)
            ->where('is_active', true)
            ->get();
    }
}
