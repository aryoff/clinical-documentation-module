<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Tests\Support\CredentialsClinicalActors;
use Modules\HospitalCore\Contracts\HospitalRegistrationContract;
use Modules\HospitalCore\Models\Department;
use Modules\HospitalCore\Models\Patient;
use Modules\HospitalCore\Models\PatientGroup;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Support\ModuleUnderTest;

/**
 * Every permission declared in module.json, proven at the authenticated HTTP
 * boundary in both its authorized and its denied outcome.
 */
class ClinicalDocumentationAuthorizationTest extends TestCase
{
    use CredentialsClinicalActors;
    use RefreshDatabase;

    private ActiveClinicalRecordContract $records;

    private User $clinician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->records = $this->app->make(ActiveClinicalRecordContract::class);
        $this->clinician = User::factory()->create();
    }

    // clinicaldocumentation.records.read

    public function test_the_document_list_is_forbidden_without_the_read_permission(): void
    {
        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.index'))
            ->assertForbidden();
    }

    public function test_the_document_list_renders_for_a_reader(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.records.read');
        $this->draft();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Index')
                ->has('documents.data', 1)
            );
    }

    public function test_reading_a_signed_document_is_forbidden_without_the_read_permission(): void
    {
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertForbidden();
    }

    public function test_a_treating_reader_reads_a_signed_document(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.records.read');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Show')
                ->where('document.document_id', $signed['document_id'])
                ->where('document.status', 'signed')
            );
    }

    // clinicaldocumentation.documents.author

    public function test_authoring_is_forbidden_without_the_author_permission(): void
    {
        $handoff = $this->handoff();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.create', ['handoff_id' => $handoff['handoff_id']]))
            ->assertForbidden();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.store'), $this->draftCommand($handoff['handoff_id']))
            ->assertForbidden();
    }

    public function test_an_authorized_author_opens_the_form_and_stores_a_private_draft(): void
    {
        $this->grantClinicalAbility($this->clinician, 'clinicaldocumentation.documents.author');
        $handoff = $this->handoff();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.create', ['handoff_id' => $handoff['handoff_id']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Create')
                ->where('handoffId', $handoff['handoff_id'])
            );

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.store'), $this->draftCommand($handoff['handoff_id']))
            ->assertRedirect();

        $this->assertDatabaseHas('cd_clinical_documents', [
            'handoff_id' => $handoff['handoff_id'],
            'author_id' => (string) $this->clinician->id,
            'status' => 'draft',
        ]);
    }

    public function test_editing_a_draft_is_forbidden_without_the_author_permission(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.edit', $draft['document_id']))
            ->assertForbidden();

        $this->actingAs($this->clinician)
            ->put(route('clinicaldocumentation.update', $draft['document_id']), ['payload' => ['subjective' => 'Edited']])
            ->assertForbidden();
    }

    public function test_an_authorized_author_edits_their_own_draft(): void
    {
        $this->grantClinicalAbility($this->clinician, 'clinicaldocumentation.documents.author');
        $draft = $this->draft();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.edit', $draft['document_id']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ClinicalDocumentation::Edit'));

        $this->actingAs($this->clinician)
            ->put(route('clinicaldocumentation.update', $draft['document_id']), ['payload' => ['subjective' => 'Edited']])
            ->assertRedirect();

        $this->assertSame(['subjective' => 'Edited'], ClinicalDocument::findOrFail($draft['document_id'])->payload);
    }

    // clinicaldocumentation.documents.sign

    public function test_signing_is_forbidden_without_the_sign_permission(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.submit', $draft['document_id']))
            ->assertForbidden();

        $this->assertDatabaseHas('cd_clinical_documents', [
            'id' => $draft['document_id'],
            'status' => 'draft',
        ]);
    }

    public function test_an_authorized_signer_locks_the_document(): void
    {
        $this->grantClinicalAbility($this->clinician, 'clinicaldocumentation.documents.sign');
        $draft = $this->draft();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.submit', $draft['document_id']))
            ->assertRedirect(route('clinicaldocumentation.show', $draft['document_id']));

        $this->assertDatabaseHas('cd_clinical_documents', [
            'id' => $draft['document_id'],
            'status' => 'signed',
            'signed_by' => (string) $this->clinician->id,
        ]);
    }

    // clinicaldocumentation.documents.amend

    public function test_amending_is_forbidden_without_the_amend_permission(): void
    {
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.amend', $signed['document_id']), $this->addendumCommand())
            ->assertForbidden();

        $this->assertDatabaseCount('cd_clinical_addenda', 0);
    }

    public function test_an_authorized_author_records_a_signed_addendum(): void
    {
        $this->grantClinicalAbility($this->clinician, 'clinicaldocumentation.documents.amend');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.amend', $signed['document_id']), $this->addendumCommand())
            ->assertRedirect(route('clinicaldocumentation.show', $signed['document_id']));

        $this->assertDatabaseHas('cd_clinical_addenda', [
            'document_id' => $signed['document_id'],
            'reason' => 'Clarifies the reported symptom duration.',
            'signed_by' => (string) $this->clinician->id,
        ]);
    }

    // clinicaldocumentation.records.stage-external-evidence

    public function test_staging_is_forbidden_without_the_staging_permission(): void
    {
        $this->requireHospitalCoreForStaging();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.presented-external-evidence.create', ['registration_id' => fake()->uuid()]))
            ->assertForbidden();
    }

    public function test_a_user_with_the_staging_permission_can_open_the_staging_form(): void
    {
        $this->requireHospitalCoreForStaging();
        $this->clinician->givePermissionTo('clinicaldocumentation.records.stage-external-evidence');
        $registration = app(HospitalRegistrationContract::class)->begin([
            'type' => 'outpatient',
            'patient_id' => Patient::factory()->create()->id,
            'department_id' => Department::factory()->create()->id,
            'patient_group_id' => PatientGroup::factory()->create()->id,
            'owner' => 'outpatient',
            'actor_id' => (string) $this->clinician->id,
        ]);
        $registrationId = $registration['registration_id'];

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.presented-external-evidence.create', ['registration_id' => $registrationId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::PresentedExternalEvidence/Create')
                ->where('registrationId', $registrationId)
            );
    }

    // clinicaldocumentation.records.break-glass

    public function test_break_glass_is_forbidden_without_the_break_glass_permission(): void
    {
        $signed = $this->signedDocument();
        $responder = User::factory()->create();

        $this->actingAs($responder)
            ->get(route('clinicaldocumentation.break-glass.create', $signed['document_id']))
            ->assertForbidden();

        $this->actingAs($responder)
            ->post(route('clinicaldocumentation.break-glass', $signed['document_id']), [
                'reason' => 'Patient arrived unconscious without accompanying records.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('cd_clinical_audit_events', ['action' => 'break_glass_read']);
    }

    public function test_an_authorized_responder_performs_a_reasoned_break_glass_read(): void
    {
        $signed = $this->signedDocument();
        $responder = User::factory()->create();
        $responder->givePermissionTo('clinicaldocumentation.records.break-glass');

        $this->actingAs($responder)
            ->get(route('clinicaldocumentation.break-glass.create', $signed['document_id']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::BreakGlass')
                ->where('documentId', $signed['document_id'])
                ->missing('document')
            );

        $this->actingAs($responder)
            ->post(route('clinicaldocumentation.break-glass', $signed['document_id']), [
                'reason' => 'Patient arrived unconscious without accompanying records.',
            ])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::BreakGlass')
                ->where('document.document_id', $signed['document_id'])
                ->where('document.security_review_required', true)
            );
    }

    // clinicaldocumentation.archive.manage

    public function test_requesting_an_archive_package_is_forbidden_without_the_archive_permission(): void
    {
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.archive', $signed['document_id']))
            ->assertForbidden();

        $this->assertDatabaseCount('cd_archive_packages', 0);
    }

    public function test_an_authorized_custodian_requests_an_archive_package(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.archive.manage');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.archive', $signed['document_id']))
            ->assertRedirect(route('clinicaldocumentation.show', $signed['document_id']));

        $this->assertDatabaseHas('cd_archive_packages', [
            'document_id' => $signed['document_id'],
            'custody_state' => 'local_retention',
        ]);
    }

    public function test_the_archive_permission_alone_does_not_reach_another_authors_document(): void
    {
        $signed = $this->signedDocument();
        $custodian = User::factory()->create();
        $custodian->givePermissionTo(['clinicaldocumentation.archive.manage', 'clinicaldocumentation.records.read']);

        // Archive custody is author-scoped by the contract, so the permission is
        // necessary and not sufficient — and the button must not be offered.
        $this->actingAs($custodian)
            ->post(route('clinicaldocumentation.archive', $signed['document_id']))
            ->assertForbidden();

        $this->assertDatabaseCount('cd_archive_packages', 0);
    }

    public function test_the_archive_action_is_offered_only_to_the_author_who_may_perform_it(): void
    {
        $this->clinician->givePermissionTo(['clinicaldocumentation.records.read', 'clinicaldocumentation.archive.manage']);
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertInertia(fn (Assert $page) => $page->where('canArchive', true));

        $custodian = User::factory()->create();
        $custodian->givePermissionTo(['clinicaldocumentation.records.read', 'clinicaldocumentation.archive.manage']);
        $this->records->acceptHandoff([
            'registration_id' => '8b1f9d6f-2a2b-4c8b-9d1c-6f7f8a9b0c1d',
            'patient_id' => 'c4b0f2a1-8e63-4c5f-9a4e-1d2c3b4a5f60',
            'source_owner' => 'inpatient',
            'source_reference_id' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            'recipient_id' => (string) $custodian->id,
            'accepted_by' => (string) $custodian->id,
        ]);

        $this->actingAs($custodian)
            ->get(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertInertia(fn (Assert $page) => $page->where('canArchive', false));
    }

    // clinicaldocumentation.audit.view

    public function test_the_audit_trail_is_forbidden_without_the_audit_permission(): void
    {
        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.audit'))
            ->assertForbidden();
    }

    public function test_an_authorized_reviewer_reads_the_audit_trail(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.audit.view');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.audit', ['document_id' => $signed['document_id']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Audit')
                ->where('filters.document_id', $signed['document_id'])
                ->has('events.data')
            );
    }

    // clinicaldocumentation.documents.author (diagnosis assertion)

    public function test_asserting_a_diagnosis_is_forbidden_without_the_author_permission(): void
    {
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.diagnoses.assert', $signed['document_id']), $this->diagnosisCommand())
            ->assertForbidden();
    }

    public function test_an_authorized_author_asserts_the_initial_diagnosis_against_their_signed_document(): void
    {
        $this->grantClinicalAbility($this->clinician, 'clinicaldocumentation.documents.author');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.diagnoses.assert', $signed['document_id']), $this->diagnosisCommand())
            ->assertRedirect(route('clinicaldocumentation.show', $signed['document_id']));

        $this->assertDatabaseHas('cd_diagnosis_assertions', [
            'document_id' => $signed['document_id'],
            'code' => 'J06.9',
            'assertion_type' => 'initial',
            'revision' => 1,
        ]);
    }

    public function test_a_refused_lineage_rule_answers_as_a_form_error_rather_than_a_server_error(): void
    {
        $this->grantClinicalAbility($this->clinician, 'clinicaldocumentation.documents.author');
        $signed = $this->signedDocument();

        // A supplement with nothing to supplement is the clinician getting
        // ahead of themselves, not a broken page.
        $this->actingAs($this->clinician)
            ->post(
                route('clinicaldocumentation.diagnoses.assert', $signed['document_id']),
                array_merge($this->diagnosisCommand(), ['assertion_type' => 'supplement']),
            )
            ->assertRedirect()
            ->assertSessionHasErrors('code');
    }

    // clinicaldocumentation.records.read (Clinical Diagnosis Read)

    public function test_the_diagnosis_history_is_forbidden_without_the_read_permission(): void
    {
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.diagnoses.index', $signed['patient_id']))
            ->assertForbidden();
    }

    public function test_a_treating_reader_reads_the_diagnosis_lineage_without_the_signed_notes(): void
    {
        $this->grantClinicalAbility($this->clinician, 'clinicaldocumentation.documents.author');
        $this->clinician->givePermissionTo('clinicaldocumentation.records.read');
        $signed = $this->signedDocument();
        $this->records->assertDiagnosis(array_merge($this->diagnosisCommand(), ['document_id' => $signed['document_id']]), (string) $this->clinician->id);

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.diagnoses.index', $signed['patient_id']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Diagnoses')
                ->where('lineage.current.0.code', 'J06.9')
                ->has('lineage.lineages', 1)
                ->missing('lineage.lineages.0.assertions.0.payload')
            );
    }

    /** @return array<string, mixed> */
    private function diagnosisCommand(): array
    {
        return [
            'coding_system' => 'ICD-10',
            'code' => 'J06.9',
            'display' => 'Acute upper respiratory infection, unspecified',
            'assertion_type' => 'initial',
        ];
    }

    /** @return array<string, mixed> */
    private function handoff(): array
    {
        return $this->records->acceptHandoff([
            'registration_id' => '8b1f9d6f-2a2b-4c8b-9d1c-6f7f8a9b0c1d',
            'patient_id' => 'c4b0f2a1-8e63-4c5f-9a4e-1d2c3b4a5f60',
            'source_owner' => 'outpatient',
            'source_reference_id' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d',
            'recipient_id' => (string) $this->clinician->id,
            'accepted_by' => (string) $this->clinician->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return $this->records->createDraft(
            $this->draftCommand($this->handoff()['handoff_id']),
            (string) $this->clinician->id,
        );
    }

    /** @return array<string, mixed> */
    private function signedDocument(): array
    {
        return $this->records->signDocument($this->draft()['document_id'], (string) $this->clinician->id);
    }

    /** @return array<string, mixed> */
    private function draftCommand(string $handoffId): array
    {
        return [
            'handoff_id' => $handoffId,
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => ['subjective' => 'Persistent headache'],
        ];
    }

    /** @return array<string, mixed> */
    private function addendumCommand(): array
    {
        return [
            'reason' => 'Clarifies the reported symptom duration.',
            'payload' => ['clarification' => 'Symptoms started three days ago.'],
            'encountered_at' => '2026-08-06T09:00:00+07:00',
        ];
    }

    private function requireHospitalCoreForStaging(): void
    {
        if (!ModuleUnderTest::isEnabled('HospitalCore')) {
            self::markTestSkipped('Presented external evidence staging requires the HospitalCore capability provider.');
        }
    }
}
