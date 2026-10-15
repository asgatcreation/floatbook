<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a shop spends to keep running: rent, generator fuel, data, salaries, repairs.
 * Each expense is paid from the outlet's cash drawer or one of its wallets and posted to
 * the ledger, so profit is net of it. Mistakes are voided (reversed), never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('outlet_id');
            $table->ulid('membership_id'); // who recorded it
            $table->string('category', 20);
            $table->bigInteger('amount'); // kobo, always positive
            $table->ulid('paid_from_account_id'); // the outlet's drawer or one of its wallets
            $table->string('description', 160)->nullable();
            $table->uuid('idempotency_key');
            $table->timestampTz('occurred_at');
            $table->date('business_date');
            $table->ulid('journal_entry_id')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->ulid('void_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['id', 'business_id']);
            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'business_date']);
            $table->index(['outlet_id', 'business_date']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets');
            $table->foreign(['membership_id', 'business_id'])->references(['id', 'business_id'])->on('memberships');
            $table->foreign(['paid_from_account_id', 'business_id'])->references(['id', 'business_id'])->on('ledger_accounts');
            $table->foreign(['journal_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
            $table->foreign(['void_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
