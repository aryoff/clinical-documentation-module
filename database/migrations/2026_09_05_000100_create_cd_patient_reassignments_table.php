<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What this context moved when a patient's identity was reconciled.
     *
     * The registry's own audit record says which of *its* tables moved; it
     * knows nothing about this context's. Without a record here, a clinician
     * asking why a signed assertion is filed under a different patient than the
     * one it was signed for has nothing to read.
     */
    public function up(): void
    {
        Schema::create('cd_patient_reassignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('canonical_patient_id')->index();
            $table->uuid('superseded_patient_id')->index();
            // Table name => rows moved. Tables deliberately left alone are
            // absent rather than recorded as zero.
            $table->json('reassigned');
            $table->uuid('reconciled_by');
            $table->text('reason');
            $table->timestamp('reconciled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cd_patient_reassignments');
    }
};
