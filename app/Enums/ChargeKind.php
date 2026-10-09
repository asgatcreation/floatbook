<?php

namespace App\Enums;

enum ChargeKind: string
{
    /** What the agent collects from the customer. */
    case CustomerCharge = 'customer_charge';

    /** What the provider deducts from the agent's wallet. */
    case ProviderFee = 'provider_fee';

    /** What the provider pays the agent (credited to the wallet). */
    case ProviderCommission = 'provider_commission';

    public function label(): string
    {
        return match ($this) {
            self::CustomerCharge => 'Customer charge',
            self::ProviderFee => 'Provider fee',
            self::ProviderCommission => 'Provider commission',
        };
    }
}
