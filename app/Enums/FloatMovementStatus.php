<?php

namespace App\Enums;

enum FloatMovementStatus: string
{
    case Requested = 'requested';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
