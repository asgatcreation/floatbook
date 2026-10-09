<?php

namespace App\Support;

enum OneTimeCodeResult
{
    case Valid;
    case Invalid;
    case Expired;
    case TooManyAttempts;

    public function message(): string
    {
        return match ($this) {
            self::Valid => 'Code accepted.',
            self::Invalid => 'That code is not correct. Check the email and try again.',
            self::Expired => 'That code has expired. Send a new one and try again.',
            self::TooManyAttempts => 'Too many wrong attempts. Send a new code and try again.',
        };
    }
}
