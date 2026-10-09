<?php

namespace App\Support\Charges;

use App\Support\Money\Money;

/**
 * What a transaction will earn and cost, worked out from the business's charge rules.
 */
final readonly class ChargeQuote
{
    public function __construct(
        public Money $charge,
        public string $chargeCollectedVia,
        public Money $providerFee,
        public Money $commission,
    ) {}

    public function profit(): Money
    {
        return $this->charge->plus($this->commission)->minus($this->providerFee);
    }

    /**
     * @return array{charge: int, charge_collected_via: string, provider_fee: int, commission: int, profit: int}
     */
    public function toArray(): array
    {
        return [
            'charge' => $this->charge->kobo,
            'charge_collected_via' => $this->chargeCollectedVia,
            'provider_fee' => $this->providerFee->kobo,
            'commission' => $this->commission->kobo,
            'profit' => $this->profit()->kobo,
        ];
    }
}
