<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Tests\TestCase;

/**
 * The diagnosis lineage added by capability 1.1.0.
 *
 * The behaviour worth protecting is not that a supersession is stored — it is
 * that the store stays append-only while the *current* answer still changes.
 * Every test here reads the current answer through the contract rather than
 * through a column, because a stored `is_current` is exactly the drift this
 * design exists to prevent.
 */
class DiagnosisLineageContractTest extends TestCase
{
    use RefreshDatabase;

    private ActiveClinicalRecordContract $records;

    private User $clinician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->records = app(ActiveClinicalRecordContract::class);
        $this->clinician = User::factory()->create();
    }

    public function test_a_supersession_advances_its_predecessors_lineage_and_becomes_the_only_current_answer(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;

        $initial = $this->records->assertDiagnosis([
            'document_id' => $signed['document_id'],
            'coding_system' => 'ICD-10',
            'code' => 'J06.9',
            'display' => 'Acute upper respiratory infection, unspecified',
            'assertion_type' => 'initial',
        ], $actor);

        $corrected = $this->records->assertDiagnosis([
            'document_id' => $signed['document_id'],
            'coding_system' => 'ICD-10',
            'code' => 'J18.9',
            'display' => 'Pneumonia, unspecified organism',
            'assertion_type' => 'supersession',
            'supersedes_assertion_id' => $initial['assertion_id'],
            'note' => 'Chest film shows consolidation.',
        ], $actor);

        $this->assertSame($initial['lineage_id'], $corrected['lineage_id']);
        $this->assertSame(1, $initial['revision']);
        $this->assertSame(2, $corrected['revision']);

        $heads = $this->records->currentDiagnosisHeads($signed['patient_id'], $actor, 'prescribing-safety-check');
        $this->assertCount(1, $heads['assertions']);
        $this->assertSame($corrected['assertion_id'], $heads['assertions'][0]['assertion_id']);
        $this->assertSame('J18.9', $heads['assertions'][0]['code']);

        // The superseded fact is still there, unchanged and readable.
        $this->assertDatabaseHas('cd_diagnosis_assertions', ['id' => $initial['assertion_id'], 'code' => 'J06.9']);
    }

    public function test_a_supplement_opens_a_parallel_lineage_so_both_diagnoses_stay_current(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;

        $initial = $this->records->assertDiagnosis($this->diagnosis($signed, 'E11.9', 'Type 2 diabetes mellitus without complications', 'initial'), $actor);
        $supplement = $this->records->assertDiagnosis($this->diagnosis($signed, 'I10', 'Essential hypertension', 'supplement'), $actor);

        $this->assertNotSame($initial['lineage_id'], $supplement['lineage_id']);
        $this->assertSame($supplement['assertion_id'], $supplement['lineage_id']);
        $this->assertSame(1, $supplement['revision']);

        $heads = $this->records->currentDiagnosisHeads($signed['patient_id'], $actor, 'prescribing-safety-check');
        $this->assertEqualsCanonicalizing(['E11.9', 'I10'], array_column($heads['assertions'], 'code'));
    }

    public function test_the_first_assertion_of_a_care_journey_must_be_initial(): void
    {
        $signed = $this->signedDocument();

        $this->expectException(\LogicException::class);
        $this->records->assertDiagnosis(
            $this->diagnosis($signed, 'I10', 'Essential hypertension', 'supplement'),
            (string) $this->clinician->id,
        );
    }

    public function test_a_second_initial_assertion_for_the_same_care_journey_is_refused(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;
        $this->records->assertDiagnosis($this->diagnosis($signed, 'E11.9', 'Type 2 diabetes mellitus', 'initial'), $actor);

        $this->expectException(\LogicException::class);
        $this->records->assertDiagnosis($this->diagnosis($signed, 'I10', 'Essential hypertension', 'initial'), $actor);
    }

    /**
     * The application refuses a second initial, but two clinicians submitting
     * at the same instant are not ordered by that check: `assertDiagnosis`
     * takes `SELECT ... FOR UPDATE` over the journey's assertions, and on a
     * journey with no rows yet there is nothing to lock. Both transactions
     * read an empty journey and both insert. Only the database can refuse
     * that, so this test writes the way the losing transaction would.
     */
    public function test_the_database_refuses_a_second_initial_assertion_for_one_care_journey(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;
        $this->records->assertDiagnosis($this->diagnosis($signed, 'E11.9', 'Type 2 diabetes mellitus', 'initial'), $actor);

        $existing = DiagnosisAssertion::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DiagnosisAssertion::query()->insert([
            'id' => (string) Str::uuid(),
            'lineage_id' => (string) Str::uuid(),
            'document_id' => $existing->document_id,
            'registration_id' => $existing->registration_id,
            'patient_id' => $existing->patient_id,
            'coding_system' => 'ICD-10',
            'code' => 'I10',
            'display' => 'Essential hypertension',
            'assertion_type' => 'initial',
            'revision' => 1,
            'asserted_by' => $actor,
            'asserted_by_name' => 'Racing clinician',
            'asserted_at' => now(),
        ]);
    }

    public function test_superseding_an_already_superseded_assertion_is_refused(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;

        $initial = $this->records->assertDiagnosis($this->diagnosis($signed, 'J06.9', 'URTI', 'initial'), $actor);
        $this->records->assertDiagnosis(array_merge(
            $this->diagnosis($signed, 'J18.9', 'Pneumonia', 'supersession'),
            ['supersedes_assertion_id' => $initial['assertion_id']],
        ), $actor);

        // Forking history is the failure mode: the caller must correct the head.
        $this->expectException(\LogicException::class);
        $this->records->assertDiagnosis(array_merge(
            $this->diagnosis($signed, 'J15.9', 'Bacterial pneumonia', 'supersession'),
            ['supersedes_assertion_id' => $initial['assertion_id']],
        ), $actor);
    }

    public function test_a_supersession_without_a_named_predecessor_is_refused(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;
        $this->records->assertDiagnosis($this->diagnosis($signed, 'J06.9', 'URTI', 'initial'), $actor);

        $this->expectException(\InvalidArgumentException::class);
        $this->records->assertDiagnosis($this->diagnosis($signed, 'J18.9', 'Pneumonia', 'supersession'), $actor);
    }

    public function test_an_assertion_is_never_updated_or_deleted(): void
    {
        $signed = $this->signedDocument();
        $initial = $this->records->assertDiagnosis($this->diagnosis($signed, 'J06.9', 'URTI', 'initial'), (string) $this->clinician->id);
        $assertion = DiagnosisAssertion::findOrFail($initial['assertion_id']);

        $this->expectException(\LogicException::class);
        $assertion->update(['code' => 'J18.9']);
    }

    public function test_the_clinical_diagnosis_read_returns_every_revision_in_order_with_its_evidence(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;
        $radiologist = User::factory()->create();

        $evidence = $this->records->recordDiagnosticResultEvidence([
            'patient_id' => $signed['patient_id'],
            'registration_id' => $signed['registration_id'],
            'source_owner' => 'radiology',
            'result_reference_id' => '5c2a7b9d-1f4e-4a35-9f2c-0d7b6a1e4c88',
            'coding_system' => 'LOINC',
            'code' => '36643-5',
            'display' => 'Chest X-ray',
            'summary' => 'Right lower lobe consolidation.',
            'observed_at' => '2026-08-07T09:15:00+07:00',
        ], (string) $radiologist->id);

        $initial = $this->records->assertDiagnosis($this->diagnosis($signed, 'J06.9', 'URTI', 'initial'), $actor);
        $corrected = $this->records->assertDiagnosis(array_merge(
            $this->diagnosis($signed, 'J18.9', 'Pneumonia', 'supersession'),
            ['supersedes_assertion_id' => $initial['assertion_id'], 'evidence_ids' => [$evidence['evidence_id']]],
        ), $actor);

        $lineage = $this->records->diagnosisLineageForPatient($signed['patient_id'], $actor, 'clinical-diagnosis-review');

        $this->assertCount(1, $lineage['lineages']);
        $thread = $lineage['lineages'][0]['assertions'];
        $this->assertSame([1, 2], array_column($thread, 'revision'));
        $this->assertFalse($thread[0]['is_current']);
        $this->assertSame($corrected['assertion_id'], $thread[0]['superseded_by']);
        $this->assertTrue($thread[1]['is_current']);
        $this->assertSame($initial['assertion_id'], $thread[1]['supersedes_assertion_id']);
        $this->assertSame('36643-5', $thread[1]['evidence'][0]['code']);
        $this->assertSame('radiology', $thread[1]['evidence'][0]['source_owner']);
        $this->assertSame([$corrected['assertion_id']], array_column($lineage['current'], 'assertion_id'));

        $this->assertDatabaseHas('cd_clinical_audit_events', [
            'action' => 'diagnosis_lineage_read',
            'actor_id' => $actor,
            'patient_id' => $signed['patient_id'],
        ]);
    }

    public function test_recording_diagnostic_result_evidence_creates_no_assertion(): void
    {
        $signed = $this->signedDocument();

        $this->records->recordDiagnosticResultEvidence([
            'patient_id' => $signed['patient_id'],
            'source_owner' => 'laboratory',
            'result_reference_id' => '2b8f0c11-9a44-4d2b-b0f1-7e6c5d4a3b21',
            'coding_system' => 'LOINC',
            'code' => '6690-2',
            'display' => 'Leukocytes',
            'observed_at' => '2026-08-07T07:00:00+07:00',
        ], (string) User::factory()->create()->id);

        $this->assertSame(0, DiagnosisAssertion::query()->count());
        $this->assertDatabaseHas('cd_diagnostic_result_evidence', ['code' => '6690-2', 'source_owner' => 'laboratory']);
    }

    public function test_evidence_from_another_patient_cannot_be_cited(): void
    {
        $signed = $this->signedDocument();
        $foreign = $this->records->recordDiagnosticResultEvidence([
            'patient_id' => '9f0e1d2c-3b4a-4958-8677-1a2b3c4d5e6f',
            'source_owner' => 'laboratory',
            'result_reference_id' => '2b8f0c11-9a44-4d2b-b0f1-7e6c5d4a3b21',
            'coding_system' => 'LOINC',
            'code' => '6690-2',
            'display' => 'Leukocytes',
            'observed_at' => '2026-08-07T07:00:00+07:00',
        ], (string) User::factory()->create()->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->records->assertDiagnosis(array_merge(
            $this->diagnosis($signed, 'J18.9', 'Pneumonia', 'initial'),
            ['evidence_ids' => [$foreign['evidence_id']]],
        ), (string) $this->clinician->id);
    }

    public function test_a_takeover_clinician_reads_the_lineage_through_the_originating_handoff_and_both_actors_are_audited(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;
        $this->records->assertDiagnosis($this->diagnosis($signed, 'J06.9', 'URTI', 'initial'), $actor);
        $takeover = User::factory()->create();

        $lineage = $this->records->diagnosisLineageForTakeover(
            $signed['patient_id'],
            (string) $takeover->id,
            $actor,
            $signed['handoff_id'],
            'ward-round-takeover',
        );

        $this->assertSame('J06.9', $lineage['current'][0]['code']);

        $metadata = json_decode((string) DB::table('cd_clinical_audit_events')
            ->where('action', 'diagnosis_lineage_read')
            ->where('actor_id', (string) $takeover->id)
            ->value('metadata'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($actor, $metadata['authorized_by']);
        $this->assertSame($signed['handoff_id'], $metadata['handoff_id']);

        // The read is diagnoses only; it never becomes document access.
        $this->expectException(AuthorizationException::class);
        $this->records->readDocument($signed['document_id'], (string) $takeover->id, 'ward-round-takeover');
    }

    /**
     * Story 15: reading is not authoring. The takeover clinician has just been
     * given the whole lineage, which is exactly the moment the distinction
     * matters — a read that quietly conferred amendment rights would let a
     * consulting clinician rewrite someone else's clinical record.
     */
    public function test_reading_the_lineage_does_not_let_the_takeover_clinician_amend_it(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;
        $initial = $this->records->assertDiagnosis($this->diagnosis($signed, 'J06.9', 'URTI', 'initial'), $actor);
        $takeover = User::factory()->create();

        $this->records->diagnosisLineageForTakeover(
            $signed['patient_id'],
            (string) $takeover->id,
            $actor,
            $signed['handoff_id'],
            'ward-round-takeover',
        );

        $this->expectException(AuthorizationException::class);
        $this->records->assertDiagnosis(array_merge(
            $this->diagnosis($signed, 'J18.9', 'Pneumonia', 'supersession'),
            ['supersedes_assertion_id' => $initial['assertion_id']],
        ), (string) $takeover->id);
    }

    public function test_the_lineage_read_is_refused_without_a_treatment_relationship(): void
    {
        $signed = $this->signedDocument();

        $this->expectException(AuthorizationException::class);
        $this->records->diagnosisLineageForPatient($signed['patient_id'], (string) User::factory()->create()->id, 'curiosity');
    }

    public function test_safety_facts_carry_current_heads_only(): void
    {
        $signed = $this->signedDocument();
        $actor = (string) $this->clinician->id;

        $initial = $this->records->assertDiagnosis($this->diagnosis($signed, 'J06.9', 'URTI', 'initial'), $actor);
        $corrected = $this->records->assertDiagnosis(array_merge(
            $this->diagnosis($signed, 'J18.9', 'Pneumonia', 'supersession'),
            ['supersedes_assertion_id' => $initial['assertion_id']],
        ), $actor);

        $facts = $this->records->safetyFactsForPatient($signed['patient_id'], $actor, 'prescribing-safety-check');

        $this->assertSame([$corrected['assertion_id']], array_column($facts['diagnoses'], 'assertion_id'));
        $this->assertSame('J18.9', $facts['diagnoses'][0]['code']);
        $this->assertSame(2, $facts['diagnoses'][0]['revision']);
    }

    /** @return array<string, mixed> */
    private function diagnosis(array $signed, string $code, string $display, string $type): array
    {
        return [
            'document_id' => $signed['document_id'],
            'coding_system' => 'ICD-10',
            'code' => $code,
            'display' => $display,
            'assertion_type' => $type,
        ];
    }

    /** @return array<string, mixed> */
    private function signedDocument(): array
    {
        $handoff = $this->records->acceptHandoff([
            'registration_id' => 'f3a5e0ad-4f0d-4795-8e5f-d62441b2a513',
            'patient_id' => 'e759fc76-5732-4fe1-a14d-a820cda7bbcb',
            'source_owner' => 'outpatient',
            'source_reference_id' => 'f0f4c31f-9c19-4d85-9a05-12f0725761db',
            'recipient_id' => (string) $this->clinician->id,
            'accepted_by' => (string) $this->clinician->id,
        ]);
        $draft = $this->records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => ['subjective' => 'Cough and fever for three days.'],
        ], (string) $this->clinician->id);

        return array_merge(
            $this->records->signDocument($draft['document_id'], (string) $this->clinician->id),
            ['handoff_id' => $handoff['handoff_id']],
        );
    }
}
