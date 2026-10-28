<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These tables carry no soft delete on purpose: they are the processing history of a destruction (PP 28/2024
        // Pasal 977), and nothing may make that disappear. They hold identifiers and receipts, never the findings.

        // One destruction: who destroyed a patient's electronic findings, why, and when.
        Schema::create('cd_destructions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id')->index();
            $table->text('reason');
            // The person acknowledged that no retained clinical record still needs the content.
            $table->boolean('dependencies_acknowledged');
            $table->foreignUuid('destroyed_by')->constrained('users');
            $table->string('destroyed_by_name')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        // The copies it covers, one row per custodian copy, tracked to a receipt.
        Schema::create('cd_destruction_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('destruction_id')->constrained('cd_destructions');
            $table->string('custodian', 40);
            $table->string('ref_type', 40);
            $table->string('ref_id', 64);
            // pending | destroyed | failed
            $table->string('status', 16)->default('pending');
            $table->string('receipt_id', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('failure', 250)->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['destruction_id', 'custodian', 'ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        foreach (['cd_destruction_items', 'cd_destructions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
