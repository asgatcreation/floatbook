<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * POS terminals.
 *
 * A shop can run several terminals, from the same provider or from different ones
 * (including bank POS). Each terminal pays into a wallet: usually its own, sometimes
 * one it shares with another terminal on the same account. Every transaction now
 * records the terminal it went through, and staff can be assigned to terminals.
 *
 * Existing data is carried over: each existing wallet gets one terminal, and every
 * transaction and statement import is linked to the terminal of its outlet and provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An outlet may now hold several wallets with the same provider, so wallet
        // accounts are no longer unique per (outlet, provider). Every other account
        // type keeps the old rule.
        DB::statement('DROP INDEX ledger_accounts_scope_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_accounts_scope_unique ON ledger_accounts
                (business_id, type, COALESCE(outlet_id, ''), COALESCE(provider_id, ''))
                WHERE type <> 'provider_wallet'
        SQL);

        Schema::table('outlet_wallets', function (Blueprint $table) {
            $table->string('name', 60)->nullable()->after('ledger_account_id');
            $table->dropUnique(['outlet_id', 'provider_id']);
            $table->unique('ledger_account_id');
            $table->index(['outlet_id', 'provider_id']);
            // Lets terminals prove their wallet is at the same outlet and provider.
            $table->unique(['id', 'business_id', 'outlet_id', 'provider_id']);
        });

        Schema::create('terminals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('outlet_id');
            $table->ulid('provider_id');
            $table->ulid('outlet_wallet_id');
            $table->string('name', 40);
            $table->string('terminal_code', 30)->nullable(); // the TID printed on receipts
            $table->string('serial_number', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'name']);
            $table->unique(['id', 'business_id']);
            $table->unique(['id', 'business_id', 'outlet_id', 'provider_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets')->cascadeOnDelete();
            $table->foreign(['provider_id', 'business_id'])->references(['id', 'business_id'])->on('providers')->cascadeOnDelete();
            $table->foreign(['outlet_wallet_id', 'business_id', 'outlet_id', 'provider_id'])
                ->references(['id', 'business_id', 'outlet_id', 'provider_id'])->on('outlet_wallets')->cascadeOnDelete();
        });

        Schema::create('membership_terminal', function (Blueprint $table) {
            $table->ulid('business_id');
            $table->ulid('membership_id');
            $table->ulid('terminal_id');

            $table->primary(['membership_id', 'terminal_id']);
            $table->index('terminal_id');
            $table->foreign(['membership_id', 'business_id'])->references(['id', 'business_id'])->on('memberships')->cascadeOnDelete();
            $table->foreign(['terminal_id', 'business_id'])->references(['id', 'business_id'])->on('terminals')->cascadeOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->ulid('terminal_id')->nullable()->after('provider_id');
            // Set when this entry replaced one recorded on the wrong terminal.
            $table->ulid('replaces_transaction_id')->nullable()->after('terminal_id');
            $table->index('terminal_id');
            $table->index('replaces_transaction_id');
        });

        Schema::table('statement_imports', function (Blueprint $table) {
            // A provider statement belongs to one terminal.
            $table->ulid('terminal_id')->nullable()->after('provider_id');
        });

        $this->carryOverExistingWallets();

        Schema::table('transactions', function (Blueprint $table) {
            // The terminal must be at the transaction's own outlet and provider.
            $table->foreign(['terminal_id', 'business_id', 'outlet_id', 'provider_id'])
                ->references(['id', 'business_id', 'outlet_id', 'provider_id'])->on('terminals');
            $table->foreign(['replaces_transaction_id', 'business_id'])->references(['id', 'business_id'])->on('transactions');
        });
        DB::statement('ALTER TABLE transactions ALTER COLUMN terminal_id SET NOT NULL');

        Schema::table('statement_imports', function (Blueprint $table) {
            $table->foreign(['terminal_id', 'business_id', 'outlet_id', 'provider_id'])
                ->references(['id', 'business_id', 'outlet_id', 'provider_id'])->on('terminals')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE statement_imports ALTER COLUMN terminal_id SET NOT NULL');

        Schema::table('outlet_wallets', function (Blueprint $table) {
            $table->dropColumn('terminal_label');
        });
    }

    public function down(): void
    {
        Schema::table('outlet_wallets', function (Blueprint $table) {
            $table->string('terminal_label', 60)->nullable();
        });
        Schema::table('statement_imports', function (Blueprint $table) {
            $table->dropForeign(['terminal_id', 'business_id', 'outlet_id', 'provider_id']);
            $table->dropColumn('terminal_id');
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['terminal_id', 'business_id', 'outlet_id', 'provider_id']);
            $table->dropForeign(['replaces_transaction_id', 'business_id']);
            $table->dropColumn(['terminal_id', 'replaces_transaction_id']);
        });
        Schema::dropIfExists('membership_terminal');
        Schema::dropIfExists('terminals');
        Schema::table('outlet_wallets', function (Blueprint $table) {
            $table->dropUnique(['id', 'business_id', 'outlet_id', 'provider_id']);
            $table->dropUnique(['ledger_account_id']);
            $table->dropIndex(['outlet_id', 'provider_id']);
            $table->dropColumn('name');
            $table->unique(['outlet_id', 'provider_id']);
        });
        DB::statement('DROP INDEX ledger_accounts_scope_unique');
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ledger_accounts_scope_unique ON ledger_accounts
                (business_id, type, COALESCE(outlet_id, ''), COALESCE(provider_id, ''))
        SQL);
    }

    /**
     * One terminal per existing wallet, named after its old label or the provider, and
     * every transaction pointed at the terminal of its outlet and provider.
     */
    private function carryOverExistingWallets(): void
    {
        $wallets = DB::table('outlet_wallets')
            ->join('providers', 'providers.id', '=', 'outlet_wallets.provider_id')
            ->select('outlet_wallets.*', 'providers.name as provider_name')
            ->get();

        foreach ($wallets as $wallet) {
            $terminalId = strtolower((string) Str::ulid());

            DB::table('terminals')->insert([
                'id' => $terminalId,
                'business_id' => $wallet->business_id,
                'outlet_id' => $wallet->outlet_id,
                'provider_id' => $wallet->provider_id,
                'outlet_wallet_id' => $wallet->id,
                'name' => $wallet->terminal_label ?: $wallet->provider_name.' POS',
                'is_active' => $wallet->is_active,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('transactions')
                ->where('outlet_id', $wallet->outlet_id)
                ->where('provider_id', $wallet->provider_id)
                ->update(['terminal_id' => $terminalId]);

            DB::table('statement_imports')
                ->where('outlet_id', $wallet->outlet_id)
                ->where('provider_id', $wallet->provider_id)
                ->update(['terminal_id' => $terminalId]);
        }
    }
};
