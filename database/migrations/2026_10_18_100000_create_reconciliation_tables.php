<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * End-of-day reconciliation.
 *
 * At closing, someone counts the drawer (note by note) and reads each wallet balance off
 * the terminal. Submitting the count closes the day for that outlet: nothing dated that
 * day can be recorded, voided or moved. A supervisor approves it (posting any shortage or
 * excess to the books, and optionally holding staff responsible for a shortage) or sends
 * it back for a recount. Only the owner can reopen an approved day, with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('outlet_id');
            $table->date('business_date');
            $table->string('status', 12); // submitted | approved | returned | reopened
            $table->bigInteger('expected_cash');
            $table->bigInteger('counted_cash');
            $table->bigInteger('cash_variance'); // counted - expected: negative is a shortage
            $table->jsonb('denominations'); // {"1000": 12, "500": 3, ..., "coins": 15000}
            $table->string('note', 300)->nullable(); // why it doesn't balance
            $table->ulid('submitted_by');
            $table->timestampTz('submitted_at');
            $table->ulid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->ulid('variance_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'business_date']);
            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'business_date']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets');
            $table->foreign(['submitted_by', 'business_id'])->references(['id', 'business_id'])->on('memberships');
            $table->foreign(['decided_by', 'business_id'])->references(['id', 'business_id'])->on('memberships');
            $table->foreign(['variance_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
        });

        Schema::create('reconciliation_wallets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('reconciliation_id');
            $table->ulid('outlet_wallet_id');
            $table->bigInteger('expected');
            $table->bigInteger('actual'); // read off the terminal screen
            $table->bigInteger('variance');

            $table->unique(['reconciliation_id', 'outlet_wallet_id']);
            $table->foreign(['reconciliation_id', 'business_id'])->references(['id', 'business_id'])->on('reconciliations')->cascadeOnDelete();
            $table->foreign(['outlet_wallet_id', 'business_id'])->references(['id', 'business_id'])->on('outlet_wallets');
        });

        Schema::create('staff_shortages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('reconciliation_id');
            $table->ulid('membership_id');
            $table->bigInteger('amount');
            $table->string('status', 12)->default('outstanding'); // outstanding | recovered | written_off
            $table->ulid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->ulid('recovery_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'status']);
            $table->foreign(['reconciliation_id', 'business_id'])->references(['id', 'business_id'])->on('reconciliations')->cascadeOnDelete();
            $table->foreign(['membership_id', 'business_id'])->references(['id', 'business_id'])->on('memberships');
            $table->foreign(['resolved_by', 'business_id'])->references(['id', 'business_id'])->on('memberships');
            $table->foreign(['recovery_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
        });

        Schema::create('reconciliation_reopens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('reconciliation_id');
            $table->ulid('reopened_by');
            $table->string('reason', 300);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['reconciliation_id', 'business_id'])->references(['id', 'business_id'])->on('reconciliations')->cascadeOnDelete();
            $table->foreign(['reopened_by', 'business_id'])->references(['id', 'business_id'])->on('memberships');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reopens');
        Schema::dropIfExists('staff_shortages');
        Schema::dropIfExists('reconciliation_wallets');
        Schema::dropIfExists('reconciliations');
    }
};
