<?php

namespace App\Support\Charges;

use App\Enums\ChargeKind;
use App\Enums\TransactionType;
use App\Models\ChargeRule;

/**
 * Typical charges for Nigerian POS businesses, created for every new business so the
 * app works on day one. They are only a starting point: every business should edit
 * them to match its own prices and its providers' actual fees and commissions.
 *
 * Amounts are kobo (₦1 = 100). percent_bps is basis points (50 = 0.5%).
 */
class StarterChargeRules
{
    /**
     * @return list<array{type: TransactionType, kind: ChargeKind, tiers: list<array<string, int|null>>}>
     */
    public static function definitions(): array
    {
        return [
            // ₦100 for every ₦5,000 (or part of it) withdrawn.
            ['type' => TransactionType::CashWithdrawal, 'kind' => ChargeKind::CustomerCharge,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'flat' => 10_000, 'step' => 500_000]]],
            // Provider takes 0.5%, capped at ₦100.
            ['type' => TransactionType::CashWithdrawal, 'kind' => ChargeKind::ProviderFee,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'percent_bps' => 50, 'cap' => 10_000]]],

            // ₦100 up to ₦10,000; ₦200 up to ₦50,000; ₦300 above.
            ['type' => TransactionType::Transfer, 'kind' => ChargeKind::CustomerCharge,
                'tiers' => [
                    ['min_amount' => 0, 'max_amount' => 1_000_000, 'flat' => 10_000],
                    ['min_amount' => 1_000_001, 'max_amount' => 5_000_000, 'flat' => 20_000],
                    ['min_amount' => 5_000_001, 'max_amount' => null, 'flat' => 30_000],
                ]],
            // ₦20 transfer fee.
            ['type' => TransactionType::Transfer, 'kind' => ChargeKind::ProviderFee,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'flat' => 2_000]]],

            // 3% commission on airtime and data.
            ['type' => TransactionType::Airtime, 'kind' => ChargeKind::ProviderCommission,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'percent_bps' => 300]]],
            ['type' => TransactionType::Data, 'kind' => ChargeKind::ProviderCommission,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'percent_bps' => 300]]],

            // ₦100 charge and ₦50 commission per bill.
            ['type' => TransactionType::BillPayment, 'kind' => ChargeKind::CustomerCharge,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'flat' => 10_000]]],
            ['type' => TransactionType::BillPayment, 'kind' => ChargeKind::ProviderCommission,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'flat' => 5_000]]],

            // Card issuance: the provider charges ₦1,000 for each card.
            ['type' => TransactionType::CardIssuance, 'kind' => ChargeKind::ProviderFee,
                'tiers' => [['min_amount' => 0, 'max_amount' => null, 'flat' => 100_000]]],
        ];
    }

    public static function install(): void
    {
        foreach (self::definitions() as $definition) {
            $rule = ChargeRule::query()->create([
                'transaction_type' => $definition['type'],
                'kind' => $definition['kind'],
                'collected_via' => 'cash',
            ]);

            foreach ($definition['tiers'] as $tier) {
                $rule->tiers()->create($tier);
            }
        }
    }
}
