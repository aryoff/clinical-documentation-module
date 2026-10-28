<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These four tables carry no soft delete on purpose. Schedules, extensions and hold events are insert-only
        // evidence of a retention decision, and a hold is only ever lifted, never removed: losing a row could only
        // shorten a patient's protection.

        // Clinical Record Retention Schedule: versioned, the floor is 25 years, a version may extend but never shorten.
        Schema::create('cd_retention_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version')->unique();
            $table->unsignedSmallInteger('minimum_years');
            $table->text('rationale');
            $table->foreignUuid('created_by')->constrained('users');
            $table->string('created_by_name')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Continuing usefulness: an explicit, attributable decision to keep a patient's records longer.
        Schema::create('cd_retention_extensions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->date('retain_until');
            $table->text('reason');
            $table->foreignUuid('decided_by')->constrained('users');
            $table->string('decided_by_name')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('patient_id');
        });

        // Clinical Record Legal Hold: the one authoritative register. Lifted once, never edited, never removed.
        Schema::create('cd_legal_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            // patient | record_set
            $table->string('scope', 16);
            // A JSON collection, so its name is plural on purpose (the singular-column rule is for scalar columns).
            $table->json('record_refs')->nullable();
            $table->text('reason');
            $table->foreignUuid('placed_by')->constrained('users');
            $table->string('placed_by_name')->nullable();
            $table->timestamp('placed_at');
            $table->timestamp('lifted_at')->nullable();
            $table->foreignUuid('lifted_by')->nullable()->constrained('users');
            $table->string('lifted_by_name')->nullable();
            $table->text('lift_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['patient_id', 'lifted_at']);
        });

        Schema::create('cd_legal_hold_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('hold_id')->constrained('cd_legal_holds');
            // placed | lifted
            $table->string('action', 16);
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('actor_name')->nullable();
            $table->text('reason');
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['hold_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['cd_legal_hold_events', 'cd_legal_holds', 'cd_retention_extensions', 'cd_retention_schedules'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
