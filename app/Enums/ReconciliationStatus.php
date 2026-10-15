<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Returned = 'returned';
    case Reopened = 'reopened';

    /** A submitted or approved count closes the day: nothing more can be recorded for it. */
    public function closesDay(): bool
    {
        return in_array($this, [self::Submitted, self::Approved], true);
    }

    /**
     * @return list<string>
     */
    public static function closing(): array
    {
        return [self::Submitted->value, self::Approved->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Returned => 'Sent back for a recount',
            self::Reopened => 'Reopened',
        };
    }
}
