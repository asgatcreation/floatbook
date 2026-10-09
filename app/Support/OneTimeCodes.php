<?php

namespace App\Support;

use App\Models\OneTimeCode;
use App\Models\User;

/**
 * Issues and checks short numeric codes sent by email.
 *
 * Codes are 6 digits, valid for 15 minutes, allow 5 wrong guesses, and only the newest
 * code for a purpose works. They are stored as an HMAC keyed with the app key, never in plain text.
 */
class OneTimeCodes
{
    public const LENGTH = 6;

    public const VALID_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function issue(User $user, string $purpose): string
    {
        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);

        OneTimeCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->delete();

        OneTimeCode::query()->create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);

        return $code;
    }

    public function verify(User $user, string $purpose, string $code): OneTimeCodeResult
    {
        $record = OneTimeCode::query()
            ->where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        if ($record === null || $record->expires_at->isPast()) {
            return OneTimeCodeResult::Expired;
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return OneTimeCodeResult::TooManyAttempts;
        }

        $record->increment('attempts');

        if (! hash_equals($record->code_hash, $this->hash(trim($code)))) {
            return $record->attempts >= self::MAX_ATTEMPTS
                ? OneTimeCodeResult::TooManyAttempts
                : OneTimeCodeResult::Invalid;
        }

        $record->forceFill(['used_at' => now()])->save();

        return OneTimeCodeResult::Valid;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
