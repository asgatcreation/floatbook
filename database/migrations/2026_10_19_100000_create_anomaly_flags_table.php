<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things worth a second look: the same reference twice, an unusually large amount, an
 * entry long after closing, a staff member voiding often, or an outlet short again.
 *
 * A flag is a question, never an accusation: someone reviews it and either says it was
 * fine (dismissed) or that something was wrong (confirmed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomaly_flags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('business_id');
            $table->ulid('outlet_id')->nullable();
            $table->ulid('membership_id')->nullable(); // whose activity it concerns
            $table->string('kind', 30);
            $table->string('severity', 10)->default('watch'); // watch | serious
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->string('summary', 200);
            $table->jsonb('details')->default('{}');
            $table->string('status', 12)->default('open'); // open | dismissed | confirmed
            $table->ulid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamps();

            $table->unique(['id', 'business_id']);
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            // One flag of a kind per subject: re-running the checks must not pile them up.
            $table->unique(['business_id', 'kind', 'subject_type', 'subject_id']);

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign(['outlet_id', 'business_id'])->references(['id', 'business_id'])->on('outlets')->cascadeOnDelete();
            $table->foreign(['membership_id', 'business_id'])->references(['id', 'business_id'])->on('memberships')->cascadeOnDelete();
            $table->foreign(['reviewed_by', 'business_id'])->references(['id', 'business_id'])->on('memberships');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomaly_flags');
    }
};
