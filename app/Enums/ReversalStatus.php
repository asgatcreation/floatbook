<?php

namespace App\Enums;

/**
 * Where a failed or pending transaction's follow-up stands.
 */
enum ReversalStatus: string
{
    /** Waiting on the provider. */
    case Open = 'open';

    /** Complaint raised with the provider (ticket logged), still waiting. */
    case Escalated = 'escalated';

    /** The provider confirmed the transaction went through after all. */
    case ResolvedSuccessful = 'resolved_successful';

    /** The provider refunded the customer. */
    case Reversed = 'reversed';

    /** Given up: the money will not come back. */
    case WrittenOff = 'written_off';

    /** The transaction itself was voided (recorded by mistake), so there is nothing to follow up. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Escalated => 'Escalated',
            self::ResolvedSuccessful => 'Went through',
            self::Reversed => 'Reversed',
            self::WrittenOff => 'Written off',
            self::Cancelled => 'Entry voided',
        };
    }

    public function isClosed(): bool
    {
        return ! in_array($this, [self::Open, self::Escalated], true);
    }
}
