<?php

namespace App\Actions\Float;

use App\Enums\FloatMovementStatus;
use App\Enums\FloatMovementType;
use App\Enums\LedgerAccountType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\FloatMovement;
use App\Models\LedgerAccount;
use App\Models\Membership;
use App\Support\Audit;
use App\Support\Ledger\Accounts;
use App\Support\Ledger\Ledger;
use App\Support\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Requests, approvals and postings for float movements.
 *
 * Who may approve: the owner approves anything. A supervisor approves transfers and
 * cash/wallet swaps between outlets they cover. Anything touching head office (top-ups,
 * pickups) or opening balances needs the owner. A movement made by someone who may
 * approve it completes straight away; otherwise it waits as a request.
 */
class FloatMovements
{
    private const HOLDS_FLOAT = [LedgerAccountType::CashDrawer, LedgerAccountType::ProviderWallet];

    public function __construct(private Accounts $accounts, private Ledger $ledger, private Audit $audit) {}

    public function request(Membership $by, FloatMovementType $type, ?string $fromId, string $toId, Money $amount, ?string $reason): FloatMovement
    {
        if ($amount->kobo <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }

        $from = $type === FloatMovementType::OpeningBalance
            ? $this->accounts->ownerCapital()
            : ($fromId !== null ? LedgerAccount::query()->find($fromId) : null);
        $to = LedgerAccount::query()->find($toId);

        $this->validateShape($type, $from, $to);
        /** @var LedgerAccount $from */
        /** @var LedgerAccount $to */
        if (! $this->canRequest($by, $type, $from, $to)) {
            throw new AuthorizationException('You can only move float for your own outlets.');
        }

        // The request and (when allowed) its immediate approval succeed or fail together.
        return DB::transaction(fn (): FloatMovement => $this->createRequest($by, $type, $from, $to, $amount, $reason));
    }

    private function createRequest(Membership $by, FloatMovementType $type, LedgerAccount $from, LedgerAccount $to, Money $amount, ?string $reason): FloatMovement
    {
        $movement = FloatMovement::query()->create([
            'type' => $type,
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => $amount->kobo,
            'status' => FloatMovementStatus::Requested,
            'reason' => $reason,
            'requested_by' => $by->user_id,
        ]);

        $this->audit->record('float.requested', $movement, after: [
            'type' => $type->value, 'amount' => $amount->kobo, 'from' => $from->name, 'to' => $to->name,
        ]);

        return $this->canApprove($by, $movement) ? $this->approve($by, $movement) : $movement;
    }

    public function approve(Membership $by, FloatMovement $movement, ?string $note = null): FloatMovement
    {
        if (! $this->canApprove($by, $movement)) {
            throw new AuthorizationException('You are not allowed to approve this float movement.');
        }

        return DB::transaction(function () use ($by, $movement, $note): FloatMovement {
            $fresh = FloatMovement::query()->whereKey($movement->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== FloatMovementStatus::Requested) {
                throw ValidationException::withMessages(['status' => 'This request has already been dealt with.']);
            }

            $from = LedgerAccount::query()->findOrFail($fresh->from_account_id);
            $to = LedgerAccount::query()->findOrFail($fresh->to_account_id);

            $entry = $this->ledger->post(
                'float_'.$fresh->type->value,
                [[$to, $fresh->amount], [$from, -$fresh->amount]],
                $to->outlet_id ?? $from->outlet_id,
                $fresh,
                $fresh->type->label().($fresh->reason ? ': '.$fresh->reason : ''),
            );

            $fresh->forceFill([
                'status' => FloatMovementStatus::Completed,
                'decided_by' => $by->user_id,
                'decided_at' => now(),
                'decision_note' => $note,
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->audit->record('float.completed', $fresh, after: ['amount' => $fresh->amount, 'type' => $fresh->type->value]);

            return $fresh;
        });
    }

    public function reject(Membership $by, FloatMovement $movement, ?string $note): FloatMovement
    {
        $cancelling = $movement->requested_by === $by->user_id;

        if (! $cancelling && ! $this->canApprove($by, $movement)) {
            throw new AuthorizationException('You are not allowed to decide this float movement.');
        }

        if ($movement->status !== FloatMovementStatus::Requested) {
            throw ValidationException::withMessages(['status' => 'This request has already been dealt with.']);
        }

        $movement->forceFill([
            'status' => $cancelling ? FloatMovementStatus::Cancelled : FloatMovementStatus::Rejected,
            'decided_by' => $by->user_id,
            'decided_at' => now(),
            'decision_note' => $note,
        ])->save();

        $this->audit->record($cancelling ? 'float.cancelled' : 'float.rejected', $movement, after: ['note' => $note]);

        return $movement;
    }

    public function canApprove(Membership $by, FloatMovement $movement): bool
    {
        if (! $by->allows(Permission::ApproveFloat)) {
            return false;
        }

        if ($by->role === Role::Owner) {
            return true;
        }

        $from = LedgerAccount::query()->find($movement->from_account_id);
        $to = LedgerAccount::query()->find($movement->to_account_id);

        return in_array($movement->type, [FloatMovementType::OutletTransfer, FloatMovementType::Rebalance], true)
            && $from?->outlet_id !== null && $to?->outlet_id !== null
            && $by->coversOutlet($from->outlet_id) && $by->coversOutlet($to->outlet_id);
    }

    private function canRequest(Membership $by, FloatMovementType $type, LedgerAccount $from, LedgerAccount $to): bool
    {
        if ($by->role === Role::Owner) {
            return true;
        }

        if ($type === FloatMovementType::OpeningBalance || ! $by->allows(Permission::RecordTransactions)) {
            return false;
        }

        // At least one side must be an outlet the person works at.
        return ($from->outlet_id !== null && $by->coversOutlet($from->outlet_id))
            || ($to->outlet_id !== null && $by->coversOutlet($to->outlet_id));
    }

    private function validateShape(FloatMovementType $type, ?LedgerAccount $from, ?LedgerAccount $to): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['to_account_id' => $message]);

        if ($from === null || $to === null) {
            $fail('Choose where the money comes from and where it goes.');
        }

        /** @var LedgerAccount $from */
        /** @var LedgerAccount $to */
        if ($from->id === $to->id) {
            $fail('Money must move between two different places.');
        }

        $isFloat = fn (LedgerAccount $a) => in_array($a->type, self::HOLDS_FLOAT, true);
        $isHeadOffice = fn (LedgerAccount $a) => $a->type === LedgerAccountType::HeadOffice;

        $valid = match ($type) {
            FloatMovementType::OpeningBalance => $from->type === LedgerAccountType::OwnerCapital && ($isFloat($to) || $isHeadOffice($to)),
            FloatMovementType::TopUp => $isHeadOffice($from) && $isFloat($to),
            FloatMovementType::CashPickup => $from->type === LedgerAccountType::CashDrawer && $isHeadOffice($to),
            FloatMovementType::OutletTransfer => $isFloat($from) && $isFloat($to) && $from->outlet_id !== $to->outlet_id,
            FloatMovementType::Rebalance => $isFloat($from) && $isFloat($to) && $from->outlet_id === $to->outlet_id,
        };

        if (! $valid) {
            $fail("Those accounts don't fit a {$type->label()}.");
        }
    }
}
