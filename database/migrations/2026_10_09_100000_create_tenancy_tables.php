<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Businesses (tenants), their outlets, and who belongs to them.
     */
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Africa/Lagos');
            $table->time('closing_time')->default('20:00');
            $table->boolean('require_two_factor')->default(false);
            $table->boolean('is_demo')->default(false)->index();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });

        Schema::create('outlets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 12);
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->unique(['business_id', 'code']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('status', 20)->default('active');
            $table->string('pin_hash')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // (id, business_id) pairs let child tables prove, at the database level,
        // that both sides of a link belong to the same business.
        Schema::table('outlets', fn (Blueprint $table) => $table->unique(['id', 'business_id']));
        Schema::table('memberships', fn (Blueprint $table) => $table->unique(['id', 'business_id']));

        Schema::create('membership_outlet', function (Blueprint $table) {
            $table->ulid('business_id');
            $table->ulid('membership_id');
            $table->ulid('outlet_id');

            $table->primary(['membership_id', 'outlet_id']);
            $table->index('outlet_id');
            $table->foreign(['membership_id', 'business_id'])->references(['id', 'business_id'])
                ->on('memberships')->cascadeOnDelete();
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])
                ->on('outlets')->cascadeOnDelete();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('business_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->jsonb('outlet_ids')->default('[]');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'email']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('current_business_id')->nullable()->after('password')
                ->constrained('businesses')->nullOnDelete();
        });

        Schema::create('one_time_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->string('code_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_business_id');
        });
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('membership_outlet');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('outlets');
        Schema::dropIfExists('businesses');
    }
};
