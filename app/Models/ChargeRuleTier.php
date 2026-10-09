<?php

namespace App\Models;

use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One band of a charge rule, e.g. "₦0 – ₦10,000: ₦100 flat" or "any amount: 0.5%, capped at ₦100".
 * All amounts are kobo; percent_bps is basis points (50 = 0.5%).
 *
 * @property int $id
 * @property string $charge_rule_id
 * @property int $min_amount
 * @property int|null $max_amount
 * @property int $flat
 * @property int $percent_bps
 * @property int|null $cap
 * @property int|null $step
 */
#[Fillable(['min_amount', 'max_amount', 'flat', 'percent_bps', 'cap', 'step'])]
class ChargeRuleTier extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'min_amount' => 'integer',
            'max_amount' => 'integer',
            'flat' => 'integer',
            'percent_bps' => 'integer',
            'cap' => 'integer',
            'step' => 'integer',
        ];
    }

    public function covers(Money $amount): bool
    {
        return $amount->kobo >= $this->min_amount && ($this->max_amount === null || $amount->kobo <= $this->max_amount);
    }

    /**
     * flat × started steps (when a step is set), otherwise flat + percentage; then capped.
     */
    public function apply(Money $amount): Money
    {
        $flat = Money::kobo($this->flat);

        $value = $this->step !== null && $this->step > 0
            ? $flat->times($amount->stepsOf(Money::kobo($this->step)))
            : $flat->plus($amount->percent($this->percent_bps));

        return $this->cap !== null ? $value->min(Money::kobo($this->cap)) : $value;
    }
}
