<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        // A Diagnosis Assertion is never edited. A correction is a successor
        // assertion carrying the same `lineage_id`, and the lineage's head is
        // derived from what nothing supersedes rather than stored, so there is
        // deliberately no `is_current` or `superseded_at` column to fall out of
        // step with the facts.
        Schema::create('cd_diagnosis_assertions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('lineage_id')->index();
            $table->uuid('document_id')->index();
            $table->uuid('registration_id')->nullable()->index();
            $table->uuid('patient_id')->index();
            $table->string('coding_system');
            $table->string('code');
            $table->string('display');
            // initial | supplement | supersession
            $table->string('assertion_type');
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('supersedes_assertion_id')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->text('note')->nullable();
            // A frozen copy of the Clinical Authority the asserting clinician
            // held, where the caller supplied one (#275). The external-transfer
            // intake requires it: an admission decided on a transcribed
            // referral has to record the credential the transcriber relied on,
            // because a licence that lapses afterwards must not erase what was
            // true when the bed was claimed. Nullable because the ordinary
            // authoring path does not yet freeze one — that gap is broader than
            // #259 and is named on it rather than closed here.
            $table->json('clinical_authority_snapshot')->nullable();
            $table->uuid('asserted_by')->index();
            $table->string('asserted_by_name');
            $table->timestamp('asserted_at')->useCurrent();
            $table->index(['patient_id', 'asserted_at']);
            $table->index(['lineage_id', 'revision']);
            // One successor per predecessor, so a lineage cannot fork.
            $table->unique('supersedes_assertion_id');
        });
        // A care journey opens once. The service checks this too, but its
        // `SELECT ... FOR UPDATE` has no rows to lock on a journey that has
        // none yet, so two simultaneous first assertions would both pass it.
        DB::statement('create unique index cd_diagnosis_assertions_one_initial_per_journey on cd_diagnosis_assertions (registration_id) where assertion_type = \'initial\'');

        // Diagnostic Result Evidence is published by a result owner (Laboratory,
        // Radiology) and cited by a clinician's assertion. Recording evidence is
        // never itself a diagnosis: only ClinicalDocumentation asserts.
        Schema::create('cd_diagnostic_result_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id')->index();
            $table->uuid('registration_id')->nullable()->index();
            // laboratory | radiology
            $table->string('source_owner');
            $table->uuid('result_reference_id')->index();
            $table->string('coding_system');
            $table->string('code');
            $table->string('display');
            $table->text('summary')->nullable();
            $table->timestamp('observed_at');
            $table->uuid('released_by')->index();
            $table->string('released_by_name');
            $table->timestamp('recorded_at')->useCurrent();
            $table->index(['patient_id', 'observed_at']);
            $table->index(['source_owner', 'result_reference_id']);
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
        Schema::dropIfExists('cd_diagnostic_result_evidence');
        Schema::dropIfExists('cd_diagnosis_assertions');
        Schema::dropIfExists('cd_clinical_addenda');
        Schema::dropIfExists('cd_clinical_documents');
        Schema::dropIfExists('cd_clinical_handoffs');
    }
};
