<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cd_presented_external_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('registration_id')->index();
            $table->uuid('patient_id')->index();
            $table->uuid('file_vault_id')->unique();
            $table->text('claim');
            $table->uuid('staged_by')->index();
            $table->string('staged_by_name');
            $table->timestamp('staged_at')->useCurrent();
            $table->index(['registration_id', 'patient_id', 'staged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cd_presented_external_evidence');
    }
};
