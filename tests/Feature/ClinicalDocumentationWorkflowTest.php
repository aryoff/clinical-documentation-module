<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The clinical safeguards the contract already guarantees, proven where a
 * clinician actually meets them: the authenticated HTTP boundary.
 */
class ClinicalDocumentationWorkflowTest extends TestCase
{
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

    public function test_a_signed_document_refuses_an_edit_and_directs_its_author_to_an_addendum(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.documents.author');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->get(route('clinicaldocumentation.edit', $signed['document_id']))
            ->assertRedirect(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertSessionHas('immutabilityNotice', 'Signed clinical documents are immutable; create an addendum.');

        $this->actingAs($this->clinician)
            ->put(route('clinicaldocumentation.update', $signed['document_id']), [
                'payload' => ['subjective' => 'Rewritten after signing'],
            ])
            ->assertRedirect(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertSessionHas('immutabilityNotice', 'Signed clinical documents are immutable; create an addendum.');

        $this->assertSame(
            ['subjective' => 'Persistent headache'],
            ClinicalDocument::findOrFail($signed['document_id'])->payload,
        );
    }

    public function test_the_refused_edit_carries_its_addendum_prompt_into_the_document_page(): void
    {
        $this->clinician->givePermissionTo([
            'clinicaldocumentation.documents.author',
            'clinicaldocumentation.records.read',
        ]);
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->followingRedirects()
            ->get(route('clinicaldocumentation.edit', $signed['document_id']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Show')
                ->where('immutabilityNotice', 'Signed clinical documents are immutable; create an addendum.')
            );
    }

    public function test_signing_an_already_signed_document_is_refused_with_the_addendum_prompt(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.documents.sign');
        $signed = $this->signedDocument();

        // A stale tab or a double submit reaches this; it must refuse, not 500.
        $this->actingAs($this->clinician)
            ->post(route('clinicaldocumentation.submit', $signed['document_id']))
            ->assertRedirect(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertSessionHas('immutabilityNotice');
    }

    public function test_signing_an_emptied_draft_is_refused_rather_than_erroring(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.documents.sign');
        $draft = $this->records->createDraft([
            'handoff_id' => $this->handoff()['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => [],
        ], (string) $this->clinician->id);

        $this->actingAs($this->clinician)
            ->from(route('clinicaldocumentation.edit', $draft['document_id']))
            ->post(route('clinicaldocumentation.submit', $draft['document_id']))
            ->assertSessionHasErrors('payload');

        $this->assertDatabaseHas('cd_clinical_documents', [
            'id' => $draft['document_id'],
            'status' => 'draft',
        ]);
    }

    public function test_break_glass_will_not_serve_its_form_for_a_private_draft(): void
    {
        $draft = $this->records->createDraft([
            'handoff_id' => $this->handoff()['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => ['subjective' => 'Persistent headache'],
        ], (string) $this->clinician->id);
        $responder = User::factory()->create();
        $responder->givePermissionTo('clinicaldocumentation.records.break-glass');

        // Serving the form would confirm the draft exists to a reader with no
        // right to know it.
        $this->actingAs($responder)
            ->get(route('clinicaldocumentation.break-glass.create', $draft['document_id']))
            ->assertNotFound();
    }

    public function test_a_clinical_document_is_never_deleted_through_the_boundary(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.documents.author');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)
            ->delete(route('clinicaldocumentation.destroy', $signed['document_id']))
            ->assertStatus(405);

        $this->assertDatabaseHas('cd_clinical_documents', ['id' => $signed['document_id']]);
    }

    public function test_reading_a_signed_document_records_a_purpose_scoped_access_event(): void
    {
        $this->clinician->givePermissionTo('clinicaldocumentation.records.read');
        $signed = $this->signedDocument();

        $this->actingAs($this->clinician)->get(route('clinicaldocumentation.show', $signed['document_id']))->assertOk();

        $read = ClinicalAuditEvent::query()
            ->where('action', 'document_read')
            ->where('document_id', $signed['document_id'])
            ->sole();

        $this->assertSame((string) $this->clinician->id, $read->actor_id);
        $this->assertSame('clinical-record-review', $read->reason);
    }

    public function test_break_glass_evidence_names_the_actor_the_reason_and_the_time_and_is_flagged_for_review(): void
    {
        $signed = $this->signedDocument();
        $responder = User::factory()->create();
        $responder->givePermissionTo('clinicaldocumentation.records.break-glass');

        $this->actingAs($responder)
            ->post(route('clinicaldocumentation.break-glass', $signed['document_id']), [
                'reason' => 'Patient arrived unconscious without accompanying records.',
            ])
            ->assertOk();

        $event = ClinicalAuditEvent::query()->where('action', 'break_glass_read')->sole();

        $this->assertSame((string) $responder->id, $event->actor_id);
        $this->assertSame($responder->name, $event->actor_name);
        $this->assertSame('Patient arrived unconscious without accompanying records.', $event->reason);
        $this->assertSame($signed['document_id'], $event->document_id);
        $this->assertNotNull($event->occurred_at);
        $this->assertTrue($event->metadata['security_review_required']);
        $this->assertNotNull($event->correlation_id);
    }

    public function test_break_glass_refuses_an_access_with_no_stated_reason(): void
    {
        $signed = $this->signedDocument();
        $responder = User::factory()->create();
        $responder->givePermissionTo('clinicaldocumentation.records.break-glass');

        $this->actingAs($responder)
            ->from(route('clinicaldocumentation.break-glass.create', $signed['document_id']))
            ->post(route('clinicaldocumentation.break-glass', $signed['document_id']), ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseMissing('cd_clinical_audit_events', ['action' => 'break_glass_read']);
    }

    public function test_break_glass_grants_the_responder_no_authoring_access(): void
    {
        $handoff = $this->handoff();
        $signed = $this->signedDocument($handoff);
        $responder = User::factory()->create();
        $responder->givePermissionTo([
            'clinicaldocumentation.records.break-glass',
            'clinicaldocumentation.documents.author',
            'clinicaldocumentation.documents.amend',
        ]);

        $this->actingAs($responder)
            ->post(route('clinicaldocumentation.break-glass', $signed['document_id']), [
                'reason' => 'Patient arrived unconscious without accompanying records.',
            ])
            ->assertOk();

        $this->actingAs($responder)
            ->post(route('clinicaldocumentation.store'), [
                'handoff_id' => $handoff['handoff_id'],
                'template' => 'soap',
                'template_version' => '1.0.0',
                'encountered_at' => '2026-08-06T10:00:00+07:00',
                'payload' => ['subjective' => 'Must not be written by an emergency reader.'],
            ])
            ->assertForbidden();

        $this->actingAs($responder)
            ->post(route('clinicaldocumentation.amend', $signed['document_id']), [
                'reason' => 'Must not be amended by an emergency reader.',
                'payload' => ['clarification' => 'Not mine to write.'],
                'encountered_at' => '2026-08-06T10:00:00+07:00',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('cd_clinical_addenda', 0);
    }

    public function test_a_read_denied_for_want_of_a_handoff_directs_a_break_glass_holder_to_the_emergency_path(): void
    {
        $signed = $this->signedDocument();
        $responder = User::factory()->create();
        // Only Break-Glass: an emergency responder is not a routine reader, and
        // the redirect is worthless if the route middleware stops them first.
        $responder->givePermissionTo('clinicaldocumentation.records.break-glass');

        $this->actingAs($responder)
            ->get(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertRedirect(route('clinicaldocumentation.break-glass.create', $signed['document_id']));

        $this->assertDatabaseMissing('cd_clinical_audit_events', ['action' => 'break_glass_read']);
    }

    public function test_a_read_denied_for_want_of_a_handoff_stays_denied_without_break_glass(): void
    {
        $signed = $this->signedDocument();
        $reader = User::factory()->create();
        $reader->givePermissionTo('clinicaldocumentation.records.read');

        $this->actingAs($reader)
            ->get(route('clinicaldocumentation.show', $signed['document_id']))
            ->assertForbidden();
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

    /**
     * @param  array<string, mixed>|null  $handoff
     * @return array<string, mixed>
     */
    private function signedDocument(?array $handoff = null): array
    {
        $draft = $this->records->createDraft([
            'handoff_id' => ($handoff ?? $this->handoff())['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => ['subjective' => 'Persistent headache'],
        ], (string) $this->clinician->id);

        return $this->records->signDocument($draft['document_id'], (string) $this->clinician->id);
    }
}
