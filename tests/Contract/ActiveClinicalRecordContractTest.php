<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Tests\TestCase;

class ActiveClinicalRecordContractTest extends TestCase
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

    public function test_an_accepted_handoff_allows_the_recipient_to_sign_an_immutable_template_versioned_document(): void
    {
        $handoff = $this->acceptHandoff();

        $draft = $this->records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => ['subjective' => 'Persistent headache'],
        ], (string) $this->clinician->id);

        $signed = $this->records->signDocument($draft['document_id'], (string) $this->clinician->id);

        $this->assertSame('signed', $signed['status']);
        $this->assertSame('soap', $signed['template']);
        $this->assertSame('1.0.0', $signed['template_version']);
        $this->assertSame('Persistent headache', $signed['payload']['subjective']);

        $this->expectException(\LogicException::class);
        $this->records->updateDraft($draft['document_id'], ['payload' => ['subjective' => 'Changed']], (string) $this->clinician->id);
    }

    public function test_a_signed_addendum_preserves_its_signed_source_without_replacement(): void
    {
        $signed = $this->signedDocument();

        $addendum = $this->records->createAddendum([
            'document_id' => $signed['document_id'],
            'reason' => 'Clarifies the reported symptom duration.',
            'encountered_at' => '2026-08-06T09:00:00+07:00',
            'payload' => ['clarification' => 'Symptoms started three days ago.'],
        ], (string) $this->clinician->id);

        $signedAddendum = $this->records->signAddendum($addendum['addendum_id'], (string) $this->clinician->id);
        $source = $this->records->readDocument($signed['document_id'], (string) $this->clinician->id, 'treatment');

        $this->assertSame('signed', $source['status']);
        $this->assertSame($signed['document_id'], $signedAddendum['document_id']);
        $this->assertSame('Clarifies the reported symptom duration.', $signedAddendum['reason']);
    }

    public function test_authorized_reads_and_safety_facts_are_purpose_scoped_and_break_glass_does_not_authorize_writing(): void
    {
        $signed = $this->signedDocument();

        $allergy = $this->records->assertAllergy([
            'document_id' => $signed['document_id'],
            'substance' => 'Penicillin',
            'reaction' => 'Anaphylaxis',
            'severity' => 'severe',
            'verification_status' => 'verified',
            'active' => true,
        ], (string) $this->clinician->id);
        $diagnosis = $this->records->assertDiagnosis([
            'document_id' => $signed['document_id'],
            'coding_system' => 'ICD-10',
            'code' => 'R51.9',
            'display' => 'Headache, unspecified',
            'assertion_type' => 'primary',
        ], (string) $this->clinician->id);

        $facts = $this->records->safetyFactsForPatient(
            $signed['patient_id'],
            (string) $this->clinician->id,
            'prescribing-safety-check',
        );
        $breakGlass = $this->records->breakGlassRead(
            $signed['document_id'],
            (string) User::factory()->create()->id,
            'Patient arrived unconscious without accompanying records.',
        );

        $this->assertSame($allergy['assertion_id'], $facts['allergies'][0]['assertion_id']);
        $this->assertSame('Penicillin', $facts['allergies'][0]['substance']);
        $this->assertSame($diagnosis['assertion_id'], $facts['diagnoses'][0]['assertion_id']);
        $this->assertTrue($breakGlass['security_review_required']);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->records->createDraft([
            'handoff_id' => $signed['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T10:00:00+07:00',
            'payload' => ['subjective' => 'Must not be written by emergency reader.'],
        ], (string) $breakGlass['accessed_by']);
    }

    public function test_a_delegated_prescriber_receives_safety_facts_only_when_the_authorizing_actor_holds_the_handoff(): void
    {
        $signed = $this->signedDocument();
        $delegate = User::factory()->create();

        $facts = $this->records->safetyFactsForDelegatedPrescriber(
            $signed['patient_id'],
            (string) $delegate->id,
            (string) $this->clinician->id,
            $signed['handoff_id'],
            'medication-prescribing',
        );

        $this->assertSame('medication-prescribing', $facts['purpose']);
        $this->assertDatabaseHas('cd_clinical_audit_events', [
            'action' => 'safety_facts_read',
            'actor_id' => (string) $delegate->id,
            'patient_id' => $signed['patient_id'],
        ]);
        $this->assertSame((string) $this->clinician->id, json_decode(
            (string) DB::table('cd_clinical_audit_events')
                ->where('action', 'safety_facts_read')
                ->where('actor_id', (string) $delegate->id)
                ->value('metadata'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['authorized_by']);
        $this->assertSame((string) $signed['handoff_id'], json_decode(
            (string) DB::table('cd_clinical_audit_events')
                ->where('action', 'safety_facts_read')
                ->where('actor_id', (string) $delegate->id)
                ->value('metadata'),
            true,
            flags: JSON_THROW_ON_ERROR,
        )['handoff_id']);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->records->readDocument($signed['document_id'], (string) $delegate->id, 'medication-prescribing');
    }

    public function test_archive_request_uses_sealed_local_retention_when_no_vault_capability_is_resolved(): void
    {
        $signed = $this->signedDocument();

        $archive = $this->records->archiveDocument($signed['document_id'], (string) $this->clinician->id);

        $this->assertSame('local_retention', $archive['custody_state']);
        $this->assertSame($signed['document_id'], $archive['document_id']);
    }

    public function test_diagnosis_assertion_rejects_a_non_icd_vocabulary_snapshot(): void
    {
        $signed = $this->signedDocument();

        $this->expectException(\InvalidArgumentException::class);
        $this->records->assertDiagnosis([
            'document_id' => $signed['document_id'],
            'coding_system' => 'free-text',
            'code' => 'headache',
            'display' => 'Headache',
            'assertion_type' => 'primary',
        ], (string) $this->clinician->id);
    }

    /** @return array<string, mixed> */
    private function acceptHandoff(): array
    {
        return $this->records->acceptHandoff([
            'registration_id' => 'f3a5e0ad-4f0d-4795-8e5f-d62441b2a513',
            'patient_id' => 'e759fc76-5732-4fe1-a14d-a820cda7bbcb',
            'source_owner' => 'outpatient',
            'source_reference_id' => 'f0f4c31f-9c19-4d85-9a05-12f0725761db',
            'recipient_id' => (string) $this->clinician->id,
            'accepted_by' => (string) $this->clinician->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function signedDocument(): array
    {
        $handoff = $this->acceptHandoff();
        $draft = $this->records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => ['subjective' => 'Persistent headache'],
        ], (string) $this->clinician->id);

        return $this->records->signDocument($draft['document_id'], (string) $this->clinician->id);
    }
}
