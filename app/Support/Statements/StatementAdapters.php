<?php

namespace App\Support\Statements;

/**
 * Picks the statement adapter for a provider. Unknown providers get the generic one.
 */
class StatementAdapters
{
    public function for(string $providerSlug): StatementAdapter
    {
        return match ($providerSlug) {
            'moniepoint' => new HeaderMatchingAdapter([
                'reference' => ['transaction reference', 'rrn'],
                'type' => ['transaction type'],
            ]),
            'opay' => new HeaderMatchingAdapter([
                'reference' => ['order no', 'order number', 'transaction no'],
                'date' => ['trade time', 'transaction time'],
            ]),
            'palmpay' => new HeaderMatchingAdapter([
                'reference' => ['order id', 'transaction id'],
                'type' => ['business type', 'transaction type'],
            ]),
            default => new HeaderMatchingAdapter,
        };
    }
}
