<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disclosure evidence is insert-only (a consent is only ever withdrawn, once), so none of these tables has a soft
        // delete: losing a row could only erase the proof of why a result was or was not handed to someone.

        // Clinical Disclosure Request: who asked for which patient's results, for what purpose.
        Schema::create('cd_disclosure_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->string('recipient_name', 120);
            // A relationship is a fact about the recipient, never the reason they are entitled.
            $table->string('recipient_relationship', 40);
            $table->string('purpose', 200);
            // Lineage ids the request is limited to (minimum necessary). A JSON collection, so plural.
            $table->json('scope_refs');
            // The exact superseded result versions the officer explicitly authorized as historical copies; none by default.
            $table->json('historical_copy_refs')->nullable();
            $table->foreignUuid('requested_by')->constrained('users');
            $table->string('requested_by_name')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['patient_id', 'created_at']);
        });

        // Clinical Disclosure Consent evidence: only ever a consent to disclose, withdrawn at most once.
        Schema::create('cd_disclosure_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('patient_id');
            $table->string('recipient_name', 120);
            $table->string('purpose', 200);
            $table->json('scope_refs');
            $table->date('valid_from');
            $table->date('valid_until');
            // Where the signed document is kept; never returned through the capability or the audit view.
            $table->string('evidence_reference', 200);
            $table->foreignUuid('recorded_by')->constrained('users');
            $table->string('recorded_by_name')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignUuid('withdrawn_by')->nullable()->constrained('users');
            $table->string('withdrawn_by_name')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->index(['patient_id', 'withdrawn_at']);
        });

        // Recipient verification: who checked identity, when, how and against what, for the basis actually used.
        Schema::create('cd_disclosure_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('cd_disclosure_requests');
            // patient_self_access | patient_authorization | representative_authority | statutory
            $table->string('basis', 32);
            // patient | recipient
            $table->string('verified_identity_of', 16);
            $table->string('method', 80);
            $table->foreignUuid('consent_id')->nullable()->constrained('cd_disclosure_consents');
            $table->string('authority_evidence_kind', 60)->nullable();
            $table->string('statutory_basis', 60)->nullable();
            $table->string('evidence_reference', 200)->nullable();
            $table->foreignUuid('verified_by')->constrained('users');
            $table->string('verified_by_name')->nullable();
            // Microsecond precision, so the last verification recorded is unambiguous even within one second.
            $table->timestamp('verified_at', 6)->useCurrent();
            $table->index(['request_id', 'verified_at']);
        });

        // Every authorization decision, granted or refused, for the exact result version it concerned.
        Schema::create('cd_disclosure_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('cd_disclosure_requests');
            $table->string('ref_type', 40);
            $table->string('ref_id', 120);
            $table->string('lineage_id', 120);
            $table->boolean('current_version');
            $table->boolean('historical_copy')->default(false);
            $table->foreignUuid('verification_id')->nullable()->constrained('cd_disclosure_verifications');
            $table->foreignUuid('consent_id')->nullable()->constrained('cd_disclosure_consents');
            $table->string('basis', 32)->nullable();
            // granted | refused
            $table->string('outcome', 8);
            $table->string('reason', 80)->nullable();
            $table->timestamp('decided_at')->useCurrent();
            $table->index(['request_id', 'decided_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cd_disclosure_decisions');
        Schema::dropIfExists('cd_disclosure_verifications');
        Schema::dropIfExists('cd_disclosure_consents');
        Schema::dropIfExists('cd_disclosure_requests');
    }
};
