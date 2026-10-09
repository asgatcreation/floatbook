<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The money core. All amounts are integer kobo (bigint); 1 naira = 100 kobo.
 *
 * Double-entry ledger: every money event is one journal entry whose lines sum to zero
 * (debits positive, credits negative). A constraint trigger checks the sum, and entries
 * and lines can never be updated or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('slug', 40);
            $table->string('color', 7)->default('#59616C');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'slug']);
            $table->unique(['id', 'business_id']);
        });

        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->ulid('outlet_id')->nullable();
            $table->ulid('provider_id')->nullable();
            $table->string('type', 30);
            $table->string('name', 80);
            // Cached running balance (sum of all lines), updated under a row lock in the
            // same database transaction as the lines. The nightly check compares the two.
            $table->bigInteger('balance')->default(0);
            $table->bigInteger('low_balance_threshold')->nullable();
            $table->timestamps();

            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'type']);
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets')->cascadeOnDelete();
            $table->foreign(['provider_id', 'business_id'])->references(['id', 'business_id'])->on('providers')->cascadeOnDelete();
        });

        // One account of each type per (outlet, provider) scope.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_accounts_scope_unique ON ledger_accounts
                (business_id, type, COALESCE(outlet_id, ''), COALESCE(provider_id, ''))
        SQL);

        Schema::create('charge_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->ulid('provider_id')->nullable();
            $table->ulid('outlet_id')->nullable();
            $table->string('transaction_type', 30);
            $table->string('kind', 30); // customer_charge | provider_fee | provider_commission
            $table->string('collected_via', 10)->default('cash'); // for customer charges: cash | wallet
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'transaction_type', 'kind']);
            $table->foreign(['provider_id', 'business_id'])->references(['id', 'business_id'])->on('providers')->cascadeOnDelete();
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets')->cascadeOnDelete();
        });

        Schema::create('charge_rule_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('charge_rule_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('min_amount')->default(0);
            $table->bigInteger('max_amount')->nullable(); // null = no upper limit
            $table->bigInteger('flat')->default(0);
            $table->unsignedInteger('percent_bps')->default(0); // basis points: 50 = 0.5%
            $table->bigInteger('cap')->nullable();
            $table->bigInteger('step')->nullable(); // "flat per every started step", e.g. ₦100 per ₦5,000

            $table->index('charge_rule_id');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->ulid('outlet_id')->nullable();
            $table->string('type', 30);
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 40)->nullable();
            $table->string('memo', 200)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->ulid('reverses_entry_id')->nullable();
            $table->timestamp('occurred_at');
            $table->date('business_date');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'business_date']);
            $table->index(['business_id', 'outlet_id', 'business_date']);
            $table->index(['source_type', 'source_id']);
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets');
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign('reverses_entry_id')->references('id')->on('journal_entries');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->ulid('business_id');
            $table->ulid('journal_entry_id');
            $table->ulid('ledger_account_id');
            $table->bigInteger('amount'); // debit > 0, credit < 0

            $table->index(['ledger_account_id', 'id']);
            $table->index('journal_entry_id');
            // Both the entry and the account must belong to the same business as the line.
            $table->foreign(['journal_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries')->cascadeOnDelete();
            $table->foreign(['ledger_account_id', 'business_id'])->references(['id', 'business_id'])->on('ledger_accounts')->cascadeOnDelete();
        });

        Schema::create('outlet_wallets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('outlet_id');
            $table->ulid('provider_id');
            $table->ulid('ledger_account_id');
            $table->string('terminal_label', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'provider_id']);
            $table->unique(['id', 'business_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets')->cascadeOnDelete();
            $table->foreign(['provider_id', 'business_id'])->references(['id', 'business_id'])->on('providers')->cascadeOnDelete();
            $table->foreign(['ledger_account_id', 'business_id'])->references(['id', 'business_id'])->on('ledger_accounts')->cascadeOnDelete();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->ulid('outlet_id');
            $table->ulid('provider_id');
            $table->ulid('membership_id');
            $table->string('type', 30);
            $table->bigInteger('amount');
            // Snapshots of what the rules worked out at the time; later rule edits never rewrite history.
            $table->bigInteger('charge')->default(0);
            $table->string('charge_collected_via', 10)->default('cash');
            $table->bigInteger('provider_fee')->default(0);
            $table->bigInteger('commission')->default(0);
            $table->string('status', 20);
            $table->boolean('cash_paid_while_pending')->default(false);
            $table->string('customer_name', 80)->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('source', 20)->default('counter');
            $table->uuid('idempotency_key');
            $table->timestamp('client_recorded_at')->nullable();
            $table->timestamp('occurred_at');
            $table->date('business_date');
            $table->ulid('journal_entry_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 200)->nullable();
            $table->ulid('void_entry_id')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'idempotency_key']);
            $table->index(['business_id', 'business_date']);
            $table->index(['business_id', 'outlet_id', 'business_date']);
            $table->index(['business_id', 'reference']);
            $table->index(['business_id', 'status']);
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets');
            $table->foreign(['provider_id', 'business_id'])->references(['id', 'business_id'])->on('providers');
            $table->foreign(['membership_id', 'business_id'])->references(['id', 'business_id'])->on('memberships');
            $table->foreign(['journal_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
            $table->foreign(['void_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_amounts_positive CHECK (amount > 0 AND charge >= 0 AND provider_fee >= 0 AND commission >= 0)');

        Schema::create('float_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->ulid('from_account_id')->nullable();
            $table->ulid('to_account_id');
            $table->bigInteger('amount');
            $table->string('status', 20);
            $table->string('reason', 200)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 200)->nullable();
            $table->ulid('journal_entry_id')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->foreign(['from_account_id', 'business_id'])->references(['id', 'business_id'])->on('ledger_accounts');
            $table->foreign(['to_account_id', 'business_id'])->references(['id', 'business_id'])->on('ledger_accounts');
            $table->foreign(['journal_entry_id', 'business_id'])->references(['id', 'business_id'])->on('journal_entries');
        });

        DB::statement('ALTER TABLE float_movements ADD CONSTRAINT float_movements_amount_positive CHECK (amount > 0)');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amount_not_zero CHECK (amount <> 0)');

        DB::unprepared(<<<'SQL'
            -- Every journal entry must balance to exactly zero. The check runs at the end of each
            -- INSERT statement, so an entry's lines must be written together in one statement.
            CREATE OR REPLACE FUNCTION journal_entry_must_balance() RETURNS trigger AS $$
            DECLARE
                total bigint;
                line_count integer;
            BEGIN
                SELECT COALESCE(SUM(amount), 0), COUNT(*) INTO total, line_count
                FROM journal_lines WHERE journal_entry_id = NEW.journal_entry_id;

                IF line_count > 0 AND total <> 0 THEN
                    RAISE EXCEPTION 'Journal entry % does not balance (off by % kobo)', NEW.journal_entry_id, total
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journal_lines_balanced
                AFTER INSERT ON journal_lines
                DEFERRABLE INITIALLY IMMEDIATE
                FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance();

            -- The ledger is append-only: corrections are made with reversing entries.
            CREATE OR REPLACE FUNCTION ledger_block_changes() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('floatbook.allow_audit_purge', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION '% is append-only (% blocked)', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_entries_immutable BEFORE UPDATE OR DELETE ON journal_entries
                FOR EACH ROW EXECUTE FUNCTION ledger_block_changes();
            CREATE TRIGGER journal_lines_immutable BEFORE UPDATE OR DELETE ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION ledger_block_changes();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_balanced ON journal_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_immutable ON journal_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_immutable ON journal_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_entry_must_balance()');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_block_changes()');

        Schema::dropIfExists('float_movements');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('outlet_wallets');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('charge_rule_tiers');
        Schema::dropIfExists('charge_rules');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('providers');
    }
};
