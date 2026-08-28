<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\FileVault;
use App\Jobs\AutoSoftDeleteFileVault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Models\PresentedExternalEvidence;
use Modules\ClinicalDocumentation\Models\PresentedExternalEvidenceReview;
use Modules\ClinicalDocumentation\Tests\Support\CredentialsClinicalActors;
use Modules\HospitalCore\Contracts\HospitalRegistrationContract;
use Modules\HospitalCore\Models\Department;
use Modules\HospitalCore\Models\Patient;
use Modules\HospitalCore\Models\PatientGroup;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Support\ModuleUnderTest;

class PresentedExternalEvidenceTest extends TestCase
{
    use CredentialsClinicalActors;
    use RefreshDatabase;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!ModuleUnderTest::isEnabled('HospitalCore')) {
            self::markTestSkipped('Presented external evidence requires the HospitalCore capability provider.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake('local');
    }

    public function test_a_registration_user_can_stage_an_external_file_against_an_active_registration(): void
    {
        $stager = $this->userWithPermission('clinicaldocumentation.records.stage-external-evidence');
        $registration = $this->activeRegistration($stager);

        $this->actingAs($stager)
            ->post(route('clinicaldocumentation.presented-external-evidence.store'), [
                'registration_id' => $registration['registration_id'],
                'claim' => 'External laboratory report from Puskesmas X.',
                'file' => UploadedFile::fake()->create('external-result.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect(route('clinicaldocumentation.presented-external-evidence.create', [
                'registration_id' => $registration['registration_id'],
            ]));

        $evidence = PresentedExternalEvidence::query()->sole();
        $file = FileVault::query()->findOrFail($evidence->file_vault_id);

        $this->assertSame($registration['registration_id'], $evidence->registration_id);
        $this->assertSame($registration['patient_id'], $evidence->patient_id);
        $this->assertSame('External laboratory report from Puskesmas X.', $evidence->claim);
        $this->assertSame((string) $stager->id, $evidence->staged_by);
        $this->assertSame('external-result.pdf', $file->original_filename);
        $this->assertTrue($file->contextProtected());
        $this->assertSame('presented_external_evidence', $file->additional_data->retention);
        $this->assertNotNull($file->last_accessed_at);
        $this->assertTrue(now()->lt(\Carbon\Carbon::parse($file->additional_data->retention_expires_at)));

        $this->assertDatabaseHas('cd_clinical_audit_events', [
            'action' => 'external_evidence_staged',
            'actor_id' => (string) $stager->id,
            'patient_id' => $registration['patient_id'],
        ]);
    }

    public function test_presented_external_evidence_has_an_explicit_filevault_expiry(): void
    {
        $stager = $this->userWithPermission('clinicaldocumentation.records.stage-external-evidence');
        $registration = $this->activeRegistration($stager);

        $this->actingAs($stager)->post(route('clinicaldocumentation.presented-external-evidence.store'), [
            'registration_id' => $registration['registration_id'],
            'claim' => 'Patient says this is a report.',
            'file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
        ]);

        $file = FileVault::query()->sole();
        $file->update([
            'additional_data' => array_merge((array) $file->additional_data, [
                'retention_expires_at' => now()->subMinute()->toIso8601String(),
            ]),
        ]);

        config(['filevault.auto_softdelete_after_days' => null]);
        (new AutoSoftDeleteFileVault())->handle();

        $this->assertSoftDeleted('file_vault', ['id' => $file->id]);
    }

    public function test_a_user_without_the_staging_permission_cannot_stage_external_evidence(): void
    {
        $user = User::factory()->create();
        $registration = $this->activeRegistration($user);

        $this->actingAs($user)
            ->post(route('clinicaldocumentation.presented-external-evidence.store'), [
                'registration_id' => $registration['registration_id'],
                'claim' => 'Must not be staged.',
                'file' => UploadedFile::fake()->create('forbidden.pdf', 20, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('cd_presented_external_evidence', 0);
        $this->assertDatabaseCount('file_vault', 0);
    }

    public function test_a_staging_only_user_cannot_author_sign_or_amend(): void
    {
        $stager = $this->userWithPermission('clinicaldocumentation.records.stage-external-evidence');
        $documentId = (string) fake()->uuid();

        $this->actingAs($stager)
            ->get(route('clinicaldocumentation.create'))
            ->assertForbidden();

        $this->actingAs($stager)
            ->post(route('clinicaldocumentation.submit', $documentId))
            ->assertForbidden();

        $this->actingAs($stager)
            ->post(route('clinicaldocumentation.amend', $documentId), ['reason' => 'Not authorized.'])
            ->assertForbidden();
    }

    public function test_the_staging_form_refuses_a_registration_that_is_not_active(): void
    {
        $stager = $this->userWithPermission('clinicaldocumentation.records.stage-external-evidence');
        $registration = $this->activeRegistration($stager);

        app(HospitalRegistrationContract::class)->release($registration['registration_id'], [
            'owner' => 'outpatient',
            'reason' => 'Journey completed.',
            'actor_id' => (string) $stager->id,
        ]);

        $this->actingAs($stager)
            ->get(route('clinicaldocumentation.presented-external-evidence.create', [
                'registration_id' => $registration['registration_id'],
            ]))
            ->assertNotFound();
    }

    public function test_a_clinician_reviews_staged_evidence_during_draft_authoring(): void
    {
        $stager = $this->userWithPermission('clinicaldocumentation.records.stage-external-evidence');
        $clinician = User::factory()->create();
        $this->grantClinicalAbility($clinician, 'clinicaldocumentation.documents.author');
        $registration = $this->activeRegistration($stager);

        $this->actingAs($stager)->post(route('clinicaldocumentation.presented-external-evidence.store'), [
            'registration_id' => $registration['registration_id'],
            'claim' => 'Patient says this is an outside imaging report.',
            'file' => UploadedFile::fake()->create('outside-imaging.pdf', 20, 'application/pdf'),
        ])->assertRedirect();

        $evidence = PresentedExternalEvidence::query()->sole();
        $records = app(ActiveClinicalRecordContract::class);
        $handoff = $records->acceptHandoff([
            'registration_id' => $registration['registration_id'],
            'patient_id' => $registration['patient_id'],
            'source_owner' => 'outpatient',
            'source_reference_id' => (string) fake()->uuid(),
            'recipient_id' => (string) $clinician->id,
            'accepted_by' => (string) $clinician->id,
        ]);
        $document = ClinicalDocument::query()->findOrFail($records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => now()->toAtomString(),
            'payload' => ['subjective' => 'Patient presented an outside report.'],
        ], (string) $clinician->id)['document_id']);

        $this->actingAs($clinician)
            ->get(route('clinicaldocumentation.edit', $document->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Edit')
                ->has('presentedExternalEvidence', 1)
                ->where('presentedExternalEvidence.0.id', $evidence->id)
            );

        $this->actingAs($clinician)
            ->get(route('clinicaldocumentation.presented-external-evidence.file', $evidence->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($stager)
            ->get(route('clinicaldocumentation.presented-external-evidence.file', $evidence->id))
            ->assertOk();

        $this->assertDatabaseHas('cd_clinical_audit_events', [
            'action' => 'external_evidence_file_read',
            'actor_id' => (string) $clinician->id,
            'document_id' => $document->id,
        ]);
        $this->assertDatabaseHas('cd_clinical_audit_events', [
            'action' => 'external_evidence_file_read',
            'actor_id' => (string) $stager->id,
            'document_id' => null,
        ]);

        $this->actingAs($this->userWithPermission('clinicaldocumentation.records.read'))
            ->get(route('clinicaldocumentation.presented-external-evidence.file', $evidence->id))
            ->assertForbidden();

        $unrelatedAuthor = $this->userWithPermission('clinicaldocumentation.documents.author');
        $this->actingAs($unrelatedAuthor)
            ->get(route('clinicaldocumentation.presented-external-evidence.file', $evidence->id))
            ->assertForbidden();

        $this->actingAs($clinician)
            ->post(route('clinicaldocumentation.presented-external-evidence.review', $evidence->id), [
                'document_id' => $document->id,
            ])
            ->assertRedirect(route('clinicaldocumentation.edit', $document->id));

        $this->actingAs($clinician)
            ->get(route('clinicaldocumentation.edit', $document->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reviewedExternalEvidence', 1)
                ->where('reviewedExternalEvidence.0.id', $evidence->id)
                ->where('reviewedExternalEvidence.0.incorporated', false)
            );

        $this->assertDatabaseHas('cd_presented_external_evidence_reviews', [
            'evidence_id' => $evidence->id,
            'document_id' => $document->id,
            'reviewed_by' => (string) $clinician->id,
        ]);
        $this->assertDatabaseHas('cd_clinical_audit_events', [
            'action' => 'external_evidence_reviewed',
            'document_id' => $document->id,
            'actor_id' => (string) $clinician->id,
        ]);

        $this->actingAs($clinician)
            ->post(route('clinicaldocumentation.presented-external-evidence.incorporate', $evidence->id), [
                'document_id' => $document->id,
            ])
            ->assertRedirect(route('clinicaldocumentation.edit', $document->id));

        $this->assertDatabaseHas('cd_clinical_audit_events', [
            'action' => 'external_evidence_incorporated',
            'document_id' => $document->id,
            'subject_id' => $evidence->id,
            'actor_id' => (string) $clinician->id,
        ]);

        $this->actingAs($clinician)
            ->get(route('clinicaldocumentation.edit', $document->id))
            ->assertInertia(fn (Assert $page) => $page
                ->missing('presentedExternalEvidence.0')
                ->has('reviewedExternalEvidence', 1)
                ->where('reviewedExternalEvidence.0.incorporated', true)
                ->where('presentedExternalEvidenceIncorporated', true)
            );

        $this->assertInstanceOf(PresentedExternalEvidenceReview::class, $evidence->refresh()->reviews()->sole());
    }

    public function test_a_staging_only_user_cannot_review_staged_evidence(): void
    {
        $stager = $this->userWithPermission('clinicaldocumentation.records.stage-external-evidence');
        $registration = $this->activeRegistration($stager);
        $this->actingAs($stager)->post(route('clinicaldocumentation.presented-external-evidence.store'), [
            'registration_id' => $registration['registration_id'],
            'claim' => 'Patient says this is a report.',
            'file' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
        ]);

        $this->actingAs($stager)
            ->post(route('clinicaldocumentation.presented-external-evidence.review', PresentedExternalEvidence::query()->sole()->id), [
                'document_id' => (string) fake()->uuid(),
            ])
            ->assertForbidden();

        $this->actingAs($stager)
            ->post(route('clinicaldocumentation.presented-external-evidence.incorporate', PresentedExternalEvidence::query()->sole()->id), [
                'document_id' => (string) fake()->uuid(),
            ])
            ->assertForbidden();
    }

    private function userWithPermission(string $permission): User
    {
        Permission::findOrCreate($permission);
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    /** @return array<string, mixed> */
    private function activeRegistration(User $actor): array
    {
        return app(HospitalRegistrationContract::class)->begin([
            'type' => 'outpatient',
            'patient_id' => Patient::factory()->create()->id,
            'department_id' => Department::factory()->create()->id,
            'patient_group_id' => PatientGroup::factory()->create()->id,
            'owner' => 'outpatient',
            'actor_id' => (string) $actor->id,
        ]);
    }
}
