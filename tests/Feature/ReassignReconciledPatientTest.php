<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Listeners\ReassignReconciledPatient;
use Modules\ClinicalDocumentation\Models\AllergyAssertion;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Models\ClinicalHandoff;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Models\PatientReassignment;
use Modules\ClinicalDocumentation\Models\PresentedExternalEvidence;
use Tests\TestCase;

/**
 * The listener is exercised directly rather than through the event bus.
 *
 * What matters is the observable state this context is responsible for — which
 * of its rows moved onto the canonical identity and which deliberately did not
 * — not how the listener iterates its models. The bus itself is the publisher's
 * contract test's problem.
 */
class ReassignReconciledPatientTest extends TestCase
{
    use RefreshDatabase;

    private string $canonical;

    private string $superseded;

    /** A third patient nobody reconciled, to prove the listener is scoped. */
    private string $bystander;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canonical = (string) Str::uuid();
        $this->superseded = (string) Str::uuid();
        $this->bystander = (string) Str::uuid();
    }

    public function test_every_current_state_table_moves_to_the_canonical_identity(): void
    {
        $this->seedClinicalRecordFor($this->superseded);

        $this->reconcile();

        foreach ($this->currentStateTables() as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('patient_id', $this->superseded)->count(),
                "[{$table}] still holds a row under the superseded identity.",
            );
            $this->assertSame(
                1,
                DB::table($table)->where('patient_id', $this->canonical)->count(),
                "[{$table}] did not move to the canonical identity.",
            );
        }
    }

    public function test_records_scoped_by_a_key_that_itself_moves_are_left_alone(): void
    {
        $this->seedClinicalRecordFor($this->superseded);
        $documentId = (string) DB::table('cd_clinical_documents')->value('id');
        $evidenceId = (string) DB::table('cd_presented_external_evidence')->value('id');

        DB::table('cd_archive_packages')->insert([
            'id' => (string) Str::uuid(),
            'document_id' => $documentId,
            'patient_id' => $this->superseded,
            'custody_state' => 'requested',
            'integrity_hash' => 'sha256:test',
            'requested_at' => now(),
        ]);
        DB::table('cd_presented_external_evidence_reviews')->insert([
            'id' => (string) Str::uuid(),
            'evidence_id' => $evidenceId,
            'document_id' => $documentId,
            'patient_id' => $this->superseded,
            'reviewed_by' => (string) Str::uuid(),
            'reviewed_by_name' => 'Reviewer',
            'reviewed_at' => now(),
        ]);

        $this->reconcile();

        // Each is reached through a key that itself reassigns (`document_id`,
        // `evidence_id`), so it stays resolvable without a second write saying
        // what the first already said. ADR 0020, and this ticket's explicit
        // acceptance criterion.
        $this->assertSame($this->superseded, DB::table('cd_archive_packages')->value('patient_id'));
        $this->assertSame($this->superseded, DB::table('cd_presented_external_evidence_reviews')->value('patient_id'));
    }

    public function test_point_in_time_records_keep_the_identity_they_were_written_under(): void
    {
        DB::table('cd_clinical_audit_events')->insert([
            'id' => (string) Str::uuid(),
            'patient_id' => $this->superseded,
            'action' => 'document.signed',
            'actor_id' => (string) Str::uuid(),
            'causer_id' => (string) Str::uuid(),
            'actor_name' => 'Dr Sari',
            'occurred_at' => now(),
        ]);

        $this->reconcile();

        // An audit event records what was true when it was written. Moving it
        // would rewrite history; it is resolved forward at read time instead.
        $this->assertSame($this->superseded, DB::table('cd_clinical_audit_events')->value('patient_id'));
    }

    public function test_another_patients_records_are_untouched(): void
    {
        $this->seedClinicalRecordFor($this->superseded);
        $this->seedClinicalRecordFor($this->bystander);

        $this->reconcile();

        foreach ($this->currentStateTables() as $table) {
            $this->assertSame(
                1,
                DB::table($table)->where('patient_id', $this->bystander)->count(),
                "[{$table}] moved a row belonging to a patient nobody reconciled.",
            );
        }
    }

    public function test_the_guard_still_refuses_every_other_write_after_the_carve_out(): void
    {
        $this->seedClinicalRecordFor($this->superseded);

        $this->reconcile();

        $assertion = DiagnosisAssertion::where('patient_id', $this->canonical)->firstOrFail();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Diagnosis Assertions are immutable');

        $assertion->update(['code' => 'A00.9']);
    }

    public function test_what_it_moved_is_recorded_for_audit(): void
    {
        $this->seedClinicalRecordFor($this->superseded);

        $this->reconcile();

        $record = PatientReassignment::firstOrFail();

        $this->assertSame($this->canonical, $record->canonical_patient_id);
        $this->assertSame($this->superseded, $record->superseded_patient_id);
        $this->assertSame('Same person, identified at the desk', $record->reason);

        foreach ($this->currentStateTables() as $table) {
            $this->assertSame(1, $record->reassigned[$table], "[{$table}] is missing from the audit record.");
        }

        // Tables this context deliberately does not move are absent rather
        // than recorded as zero, so the record says what was decided.
        $this->assertArrayNotHasKey('cd_archive_packages', $record->reassigned);
        $this->assertArrayNotHasKey('cd_clinical_audit_events', $record->reassigned);
    }

    public function test_a_reconciliation_with_nothing_to_move_is_still_recorded(): void
    {
        $this->reconcile();

        $record = PatientReassignment::firstOrFail();

        // "Saw it, had nothing to move" and "never heard about it" are
        // different answers, and only the record tells them apart.
        $this->assertSame(0, $record->reassigned['cd_diagnosis_assertions']);
    }

    /** @return list<string> */
    private function currentStateTables(): array
    {
        return [
            'cd_clinical_handoffs',
            'cd_clinical_documents',
            'cd_diagnosis_assertions',
            'cd_allergy_assertions',
            'cd_diagnostic_result_evidence',
            'cd_presented_external_evidence',
        ];
    }

    private function reconcile(): void
    {
        (new ReassignReconciledPatient())->handle([
            'canonical_patient_id' => $this->canonical,
            'superseded_patient_id' => $this->superseded,
            'canonical_nocm' => 'RM-000123',
            'superseded_nocm' => 'RM-000999',
            'reconciled_by' => (string) Str::uuid(),
            'reason' => 'Same person, identified at the desk',
            'reconciled_at' => now()->toIso8601String(),
        ]);
    }

    /** One row in each current-state table, all signed and immutable. */
    private function seedClinicalRecordFor(string $patientId): void
    {
        $registrationId = (string) Str::uuid();
        $clinicianId = (string) Str::uuid();

        $handoff = ClinicalHandoff::create([
            'registration_id' => $registrationId,
            'patient_id' => $patientId,
            'source_owner' => 'EmergencyRegistration',
            'source_reference_id' => (string) Str::uuid(),
            'recipient_id' => $clinicianId,
            'accepted_by' => $clinicianId,
            'accepted_by_name' => 'Dr Sari',
            'accepted_at' => now(),
        ]);

        $document = ClinicalDocument::create([
            'handoff_id' => $handoff->id,
            'registration_id' => $registrationId,
            'patient_id' => $patientId,
            'template' => 'emergency-note',
            'template_version' => '1',
            'status' => 'signed',
            'author_id' => $clinicianId,
            'author_name' => 'Dr Sari',
            'payload' => ['assessment' => 'Chest pain'],
            'encountered_at' => now(),
            'signed_at' => now(),
            'signed_by' => $clinicianId,
            'signed_by_name' => 'Dr Sari',
        ]);

        DiagnosisAssertion::create([
            'document_id' => $document->id,
            'registration_id' => $registrationId,
            'patient_id' => $patientId,
            'coding_system' => 'ICD-10',
            'code' => 'I21.9',
            'display' => 'Acute myocardial infarction, unspecified',
            'assertion_type' => 'initial',
            'asserted_by' => $clinicianId,
            'asserted_by_name' => 'Dr Sari',
            'asserted_at' => now(),
        ]);

        AllergyAssertion::create([
            'document_id' => $document->id,
            'patient_id' => $patientId,
            'substance' => 'Penicillin',
            'reaction' => 'Anaphylaxis',
            'severity' => 'severe',
            'verification_status' => 'confirmed',
            'active' => true,
            'asserted_by' => $clinicianId,
            'asserted_by_name' => 'Dr Sari',
            'asserted_at' => now(),
        ]);

        DiagnosticResultEvidence::create([
            'patient_id' => $patientId,
            'registration_id' => $registrationId,
            'source_owner' => 'laboratory',
            'result_reference_id' => (string) Str::uuid(),
            'coding_system' => 'LOINC',
            'code' => '10839-9',
            'display' => 'Troponin I',
            'observed_at' => now(),
            'released_by' => $clinicianId,
            'released_by_name' => 'Lab Analyst',
            'recorded_at' => now(),
        ]);

        PresentedExternalEvidence::create([
            'registration_id' => $registrationId,
            'patient_id' => $patientId,
            'file_vault_id' => (string) Str::uuid(),
            'claim' => 'Referral letter from the district clinic',
            'staged_by' => $clinicianId,
            'staged_by_name' => 'Registration Clerk',
            'staged_at' => now(),
        ]);
    }
}
