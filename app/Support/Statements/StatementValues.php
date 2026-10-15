<?php

namespace App\Support\Statements;

use App\Enums\TransactionType;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Turns the text in a statement cell into amounts, times and transaction types.
 * Unreadable values return null so the row can be flagged instead of guessed.
 */
class StatementValues
{
    /** Date formats seen in Nigerian exports. Day comes before month. */
    private const DATE_FORMATS = [
        'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d',
        'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y g:i A', 'd/m/Y g:i:s A', 'd/m/Y',
        'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
        'd M Y H:i', 'd M Y, H:i', 'd M Y g:i A', 'd M Y',
        'j M Y, g:i A', 'M j, Y g:i A', 'M j, Y',
    ];

    /**
     * An amount in kobo, always positive (statements often show debits as negative or in brackets).
     */
    public function amount(string $value): ?int
    {
        $clean = str_replace(['₦', 'NGN', 'N', ',', ' ', '(', ')', '+', '-'], '', trim($value));

        if ($clean === '') {
            return null;
        }

        try {
            $kobo = Money::parse($clean)->kobo;
        } catch (InvalidArgumentException) {
            return null;
        }

        return $kobo > 0 ? $kobo : null;
    }

    /**
     * A moment in time, read in the business's time zone and returned in UTC.
     */
    public function date(string $value, string $timezone): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // A bare number is an Excel date serial (days since 30 Dec 1899).
        if (is_numeric($value) && (float) $value > 20_000 && (float) $value < 80_000) {
            $seconds = (int) round(((float) $value - 25569) * 86_400);

            return CarbonImmutable::createFromTimestampUTC($seconds)->shiftTimezone($timezone)->utc();
        }

        foreach (self::DATE_FORMATS as $format) {
            // Strict: a format only counts if PHP parsed the whole value without warnings
            // (so "31/12/2026" is never read as month 31).
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone($timezone));
            $errors = \DateTimeImmutable::getLastErrors();

            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return CarbonImmutable::instance($parsed)->utc();
            }
        }

        return null;
    }

    /**
     * Best guess at the transaction type from a description such as "Cash Withdrawal" or "DSTV payment".
     */
    public function type(string $value): ?TransactionType
    {
        $text = strtolower($value);

        return match (true) {
            $text === '' => null,
            str_contains($text, 'withdraw'), str_contains($text, 'cash out'), str_contains($text, 'cashout'),
            str_contains($text, 'pos purchase'), str_contains($text, 'card') && str_contains($text, 'debit') => TransactionType::CashWithdrawal,
            str_contains($text, 'airtime'), str_contains($text, 'recharge'), str_contains($text, 'vtu') => TransactionType::Airtime,
            str_contains($text, 'data') => TransactionType::Data,
            str_contains($text, 'bill'), str_contains($text, 'electric'), str_contains($text, 'dstv'),
            str_contains($text, 'gotv'), str_contains($text, 'startimes'), str_contains($text, 'disco'), str_contains($text, 'cable') => TransactionType::BillPayment,
            str_contains($text, 'bvn'), str_contains($text, 'nin') => TransactionType::BvnNin,
            str_contains($text, 'transfer'), str_contains($text, 'deposit'), str_contains($text, 'cash in'), str_contains($text, 'send') => TransactionType::Transfer,
            default => null,
        };
    }

    /**
     * Whether the provider says this transaction did not go through.
     */
    public function isFailed(string $status): bool
    {
        $text = strtolower($status);

        foreach (['fail', 'declin', 'revers', 'reject', 'error', 'cancel'] as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        return false;
    }
}
