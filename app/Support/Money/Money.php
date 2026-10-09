<?php

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An amount of Nigerian naira held as integer kobo (1 naira = 100 kobo).
 *
 * Money never touches floating point: percentages are basis points (1 bp = 0.01%) and
 * every division states its rounding. Instances are immutable.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(public readonly int $kobo) {}

    public static function kobo(int $kobo): self
    {
        return new self($kobo);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parse what a person typed, e.g. "5,000", "₦5000.50" or "5000.5". Rejects anything
     * with more than two decimal places instead of silently rounding it.
     */
    public static function parse(string $input): self
    {
        $clean = str_replace([',', ' ', '₦', 'NGN', 'N'], '', trim($input));

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $clean, $m)) {
            throw new InvalidArgumentException("\"{$input}\" is not a valid naira amount.");
        }

        $kobo = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return new self($m[1] === '-' ? -$kobo : $kobo);
    }

    public static function naira(int $naira): self
    {
        return new self($naira * 100);
    }

    public function plus(self $other): self
    {
        return new self($this->kobo + $other->kobo);
    }

    public function minus(self $other): self
    {
        return new self($this->kobo - $other->kobo);
    }

    public function negate(): self
    {
        return new self(-$this->kobo);
    }

    public function times(int $factor): self
    {
        return new self($this->kobo * $factor);
    }

    /**
     * A percentage given in basis points (50 = 0.5%), rounded half away from zero to the nearest kobo.
     */
    public function percent(int $basisPoints): self
    {
        return new self(self::roundedDiv($this->kobo * $basisPoints, 10_000));
    }

    /**
     * How many started steps of the given size fit in this amount (e.g. ₦5,001 is 2 steps of ₦5,000).
     */
    public function stepsOf(self $step): int
    {
        if ($step->kobo <= 0) {
            throw new InvalidArgumentException('Step must be positive.');
        }

        return intdiv($this->kobo + $step->kobo - 1, $step->kobo);
    }

    public function min(self $other): self
    {
        return $this->kobo <= $other->kobo ? $this : $other;
    }

    public function isZero(): bool
    {
        return $this->kobo === 0;
    }

    public function isNegative(): bool
    {
        return $this->kobo < 0;
    }

    public function equals(self $other): bool
    {
        return $this->kobo === $other->kobo;
    }

    /**
     * "₦12,500.00", or "₦12,500" with $trimZeroKobo. Negative amounts use a real minus sign.
     */
    public function format(bool $trimZeroKobo = false): string
    {
        $abs = abs($this->kobo);
        $naira = number_format(intdiv($abs, 100));
        $kobo = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        $text = $trimZeroKobo && $kobo === '00' ? "₦{$naira}" : "₦{$naira}.{$kobo}";

        return $this->kobo < 0 ? '−'.$text : $text;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    public function jsonSerialize(): int
    {
        return $this->kobo;
    }

    /**
     * Integer division rounding half away from zero.
     */
    private static function roundedDiv(int $numerator, int $denominator): int
    {
        $quotient = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if (abs($remainder) * 2 >= $denominator) {
            $quotient += $numerator < 0 ? -1 : 1;
        }

        return $quotient;
    }
}
