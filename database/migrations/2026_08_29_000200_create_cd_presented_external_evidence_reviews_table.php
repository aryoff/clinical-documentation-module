<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cd_presented_external_evidence_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('evidence_id')->unique();
            $table->uuid('document_id')->index();
            $table->uuid('patient_id')->index();
            $table->uuid('reviewed_by')->index();
            $table->string('reviewed_by_name');
            $table->timestamp('reviewed_at')->useCurrent();
            $table->index(['document_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cd_presented_external_evidence_reviews');
    }
};
