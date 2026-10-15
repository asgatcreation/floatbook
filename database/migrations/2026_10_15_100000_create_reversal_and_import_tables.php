<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversal follow-up for failed/pending transactions, and provider statement imports.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Lets child rows prove (at the database level) they belong to the transaction's business.
        Schema::table('transactions', fn (Blueprint $table) => $table->unique(['id', 'business_id']));

        Schema::create('reversal_cases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->ulid('transaction_id');
            $table->ulid('outlet_id');
            $table->bigInteger('amount');
            $table->string('customer_name', 80)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('provider_ticket', 60)->nullable();
            // open | escalated | resolved_successful | reversed | written_off
            $table->string('status', 30)->default('open');
            $table->string('outcome', 30)->nullable(); // for closed cases with cash paid: cash_recovered | written_off
            $table->timestamp('due_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('journal_entry_id')->nullable();
            $table->timestamps();

            $table->unique('transaction_id');
            $table->index(['business_id', 'status', 'due_at']);
            $table->foreign(['transaction_id', 'business_id'])->references(['id', 'business_id'])->on('transactions');
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets');
            $table->foreign(['journal_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
        });

        Schema::create('reversal_case_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('reversal_case_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('reversal_case_id');
        });

        Schema::create('statement_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->ulid('outlet_id');
            $table->ulid('provider_id');
            $table->string('original_filename', 200);
            $table->string('status', 20)->default('uploaded'); // uploaded | mapped | committed | discarded
            $table->jsonb('headers');
            $table->jsonb('column_mapping')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'created_at']);
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets');
            $table->foreign(['provider_id', 'business_id'])->references(['id', 'business_id'])->on('providers');
        });

        Schema::create('statement_import_rows', function (Blueprint $table) {
            $table->id();
            $table->ulid('business_id');
            $table->ulid('statement_import_id');
            $table->unsignedInteger('row_number');
            $table->jsonb('raw');
            $table->string('reference', 80)->nullable();
            $table->bigInteger('amount')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->string('type', 30)->nullable();
            $table->string('provider_status', 40)->nullable();
            $table->string('row_hash', 64);
            // pending (not analysed) | matched | missing_in_floatbook | amount_mismatch | duplicate | invalid | created
            $table->string('match_status', 30)->default('pending');
            $table->string('problem', 200)->nullable();
            $table->ulid('matched_transaction_id')->nullable();

            $table->index(['statement_import_id', 'row_number']);
            $table->index(['business_id', 'row_hash']);
            $table->foreign(['statement_import_id', 'business_id'])->references(['id', 'business_id'])->on('statement_imports')->cascadeOnDelete();
            $table->foreign(['matched_transaction_id', 'business_id'])->references(['id', 'business_id'])->on('transactions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_import_rows');
        Schema::dropIfExists('statement_imports');
        Schema::dropIfExists('reversal_case_updates');
        Schema::dropIfExists('reversal_cases');
        Schema::table('transactions', fn (Blueprint $table) => $table->dropUnique(['id', 'business_id']));
    }
};
