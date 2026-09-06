<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Contracts\EmergencyAccessReviewPort;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Tests\TestCase;

/**
 * Consumer contract test for `hospitalcore.emergency-access-review` `^1.0`.
 *
 * Break-glass over a signed document is granted and audited here; the review of
 * it is the facility's, and this module publishes into it rather than running a
 * second queue beside the ward's.
 *
 * The two assertions that matter are that the audit row survives independently
 * of the queue — the evidence is not merged, only the review — and that what
 * this module publishes carries nothing a reviewer is not entitled to read.
 */
class EmergencyAccessReviewConsumerTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyAccessReviewPort $queue;

    private ActiveClinicalRecordContract $records;

    private User $author;

    private User $emergencyReader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = app(EmergencyAccessReviewPort::class);
        $this->records = app(ActiveClinicalRecordContract::class);
        $this->author = User::factory()->create(['name' => 'dr. Sri Handayani']);
        $this->emergencyReader = User::factory()->create(['name' => 'dr. Bagus Wirawan']);
    }

    public function test_the_capability_is_reachable_through_the_port(): void
    {
        $this->assertInstanceOf(EmergencyAccessReviewPort::class, $this->queue);
    }

    public function test_the_module_declares_this_capability_as_required(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($manifest['capabilities']['consumes'] as $consumed) {
            if ($consumed['id'] === 'hospitalcore.emergency-access-review') {
                // Required, because a break-glass this module could not publish
                // is one nobody can review, and an emergency route with no
                // review behind it is not a control.
                $this->assertTrue($consumed['required']);

                return;
            }
        }

        $this->fail('ClinicalDocumentation no longer consumes the emergency-access-review capability.');
    }

    public function test_a_break_glass_read_is_published_as_a_reviewable_fact(): void
    {
        $document = $this->signedDocument();

        $read = $this->records->breakGlassRead(
            $document->id,
            (string) $this->emergencyReader->id,
            'Patient arrived unconscious; need this note now.',
        );

        $published = $this->publishedFact();

        $this->assertSame($read['correlation_id'], $published['correlation_id']);
        $this->assertSame('clinicaldocumentation', $published['granting_context']);
        $this->assertSame('clinical-document', $published['subject_kind']);
        $this->assertSame($document->id, $published['subject_id']);
        $this->assertSame((string) $this->emergencyReader->id, $published['actor_id']);
        $this->assertSame('pending', $published['review_state']);
    }

    /**
     * A caller already inside an emergency joins it rather than opening a
     * second one.
     */
    public function test_a_supplied_correlation_id_joins_an_emergency_already_open(): void
    {
        $document = $this->signedDocument();
        $emergency = 'c1f0a6e3-4b6d-4a2e-9c31-8b0e5f2a7d44';

        $read = $this->records->breakGlassRead(
            $document->id,
            (string) $this->emergencyReader->id,
            'Ward broke glass on the episode first; this is the note it points at.',
            $emergency,
        );

        $this->assertSame($emergency, $read['correlation_id']);
        $this->assertSame($emergency, $this->publishedFact()['correlation_id']);
    }

    /**
     * Publishing does not replace this context's own audit.
     *
     * The constraint on #251 is explicit: each context keeps its own record of
     * what it granted, and what is unified is the review, not the evidence.
     */
    public function test_the_modules_own_audit_row_is_written_as_well(): void
    {
        $document = $this->signedDocument();

        $this->records->breakGlassRead(
            $document->id,
            (string) $this->emergencyReader->id,
            'Patient arrived unconscious; need this note now.',
        );

        $audit = ClinicalAuditEvent::query()->where('action', 'break_glass_read')->sole();

        $this->assertSame($document->id, $audit->document_id);
        $this->assertSame($this->publishedFact()['correlation_id'], $audit->correlation_id);
        // The queue cites the audit row, so a reviewer's finding is traceable
        // back to the evidence it was made about.
        $this->assertSame($audit->id, $this->publishedFact()['source_event_id']);
    }

    /** What crosses the boundary names the document and never describes it. */
    public function test_the_published_fact_carries_no_clinical_content(): void
    {
        $document = $this->signedDocument();

        $this->records->breakGlassRead(
            $document->id,
            (string) $this->emergencyReader->id,
            'Patient arrived unconscious; need this note now.',
        );

        foreach (['payload', 'template', 'content', 'document'] as $clinical) {
            $this->assertArrayNotHasKey(
                $clinical,
                $this->publishedFact(),
                "The published fact carries [{$clinical}]. A reviewer judges the access, never the note.",
            );
        }
    }

    private function signedDocument(): ClinicalDocument
    {
        $handoff = $this->records->acceptHandoff([
            'registration_id' => '2f2f9d1b-63c2-4b53-9b0f-2f2a4b7d6e01',
            'patient_id' => 'b6b2a0f4-1f1c-4a52-9a1e-2c9f4b9d5a01',
            'source_owner' => 'outpatient',
            'source_reference_id' => '7c1a5e88-3d6f-4c21-9b0a-51ee2a4c0f77',
            'recipient_id' => (string) $this->author->id,
            'accepted_by' => (string) $this->author->id,
        ]);

        $draft = $this->records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'payload' => ['subjective' => 'Chest pain.'],
        ], (string) $this->author->id);

        $this->records->signDocument($draft['document_id'], (string) $this->author->id);

        return ClinicalDocument::findOrFail($draft['document_id']);
    }

    /** @return array<string, mixed> */
    private function publishedFact(): array
    {
        // Read back through the provider, because the point of the boundary is
        // that this module does not know the queue's storage.
        $queue = app(\Modules\HospitalCore\Contracts\EmergencyAccessReviewContract::class)
            ->emergencyAccessQueue();

        $this->assertNotSame([], $queue, 'Nothing was published to the emergency-access queue.');

        return $queue[0];
    }
}
