<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cd_clinical_handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('registration_id')->index();
            $table->uuid('patient_id')->index();
            $table->string('source_owner');
            $table->uuid('source_reference_id');
            $table->uuid('recipient_id')->index();
            $table->uuid('accepted_by');
            $table->string('accepted_by_name');
            $table->timestamp('accepted_at')->useCurrent();
            $table->index(['registration_id', 'recipient_id', 'accepted_at']);
        });

        Schema::create('cd_clinical_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('handoff_id')->index();
            $table->uuid('registration_id')->index();
            $table->uuid('patient_id')->index();
            $table->string('template');
            $table->string('template_version');
            $table->string('status')->default('draft');
            $table->uuid('author_id')->index();
            $table->string('author_name');
            $table->json('payload');
            $table->timestamp('encountered_at');
            $table->timestamp('signed_at')->nullable();
            $table->uuid('signed_by')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->timestamps();
            $table->index(['registration_id', 'status', 'signed_at']);
            $table->index(['patient_id', 'status', 'signed_at']);
        });

        Schema::create('cd_clinical_addenda', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('author_id')->index();
            $table->string('author_name');
            $table->text('reason');
            $table->json('payload');
            $table->timestamp('encountered_at');
            $table->timestamp('signed_at')->nullable();
            $table->uuid('signed_by')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'signed_at']);
        });

        Schema::create('cd_diagnosis_assertions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('patient_id')->index();
            $table->string('coding_system');
            $table->string('code');
            $table->string('display');
            $table->string('assertion_type');
            $table->text('note')->nullable();
            $table->uuid('asserted_by')->index();
            $table->string('asserted_by_name');
            $table->timestamp('asserted_at')->useCurrent();
            $table->index(['patient_id', 'asserted_at']);
        });

        Schema::create('cd_allergy_assertions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('patient_id')->index();
            $table->string('substance');
            $table->text('reaction');
            $table->string('severity');
            $table->string('verification_status');
            $table->boolean('active');
            $table->uuid('asserted_by')->index();
            $table->string('asserted_by_name');
            $table->timestamp('asserted_at')->useCurrent();
            $table->index(['patient_id', 'active', 'asserted_at']);
        });

        Schema::create('cd_clinical_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id')->nullable()->index();
            $table->uuid('document_id')->nullable()->index();
            $table->uuid('addendum_id')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('action');
            $table->uuid('actor_id')->index();
            $table->uuid('causer_id');
            $table->string('actor_name');
            $table->text('reason')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['patient_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['causer_id', 'occurred_at']);
        });

        Schema::create('cd_archive_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->unique();
            $table->uuid('patient_id')->index();
            $table->string('custody_state');
            $table->string('integrity_hash');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('custody_acknowledged_at')->nullable();
            $table->index(['custody_state', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cd_archive_packages');
        Schema::dropIfExists('cd_clinical_audit_events');
        Schema::dropIfExists('cd_allergy_assertions');
        Schema::dropIfExists('cd_diagnosis_assertions');
        Schema::dropIfExists('cd_clinical_addenda');
        Schema::dropIfExists('cd_clinical_documents');
        Schema::dropIfExists('cd_clinical_handoffs');
    }
};
