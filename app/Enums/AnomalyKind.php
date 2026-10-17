<?php

namespace App\Enums;

enum AnomalyKind: string
{
    case DuplicateReference = 'duplicate_reference';
    case LargeAmount = 'large_amount';
    case AfterHours = 'after_hours';
    case FrequentVoids = 'frequent_voids';
    case RepeatedShortage = 'repeated_shortage';

    public function label(): string
    {
        return match ($this) {
            self::DuplicateReference => 'Same reference twice',
            self::LargeAmount => 'Unusually large',
            self::AfterHours => 'Recorded after hours',
            self::FrequentVoids => 'Voiding often',
            self::RepeatedShortage => 'Short again',
        };
    }

    /** What to do about it, in one line. */
    public function hint(): string
    {
        return match ($this) {
            self::DuplicateReference => 'Two entries share a provider reference. One may have been recorded twice.',
            self::LargeAmount => 'Much bigger than this outlet usually takes. Check the receipt.',
            self::AfterHours => 'Recorded well after closing time.',
            self::FrequentVoids => 'This person has voided several entries recently.',
            self::RepeatedShortage => 'This outlet has come up short more than once lately.',
        };
    }
}
