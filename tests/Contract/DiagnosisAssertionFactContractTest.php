<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Services\DiagnosisAssertionFactPublisher;
use Tests\TestCase;

/**
 * The async diagnosis fact an integration consumes instead of this module's
 * storage. The property that matters is that the payload is self-sufficient:
 * a consumer that queues it must be able to submit it later without asking
 * anything else, and without a subsequent supersession changing what it says.
 */
class DiagnosisAssertionFactContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_assertion_publishes_a_complete_scalar_snapshot(): void
    {
        $published = [];
        Event::listen(DiagnosisAssertionFactPublisher::EVENT, function (array $fact) use (&$published): void {
            $published[] = $fact;
        });
        $records = app(ActiveClinicalRecordContract::class);
        $clinician = User::factory()->create();
        $signed = $this->signedDocument($records, $clinician);

        $records->assertDiagnosis([
            'document_id' => $signed['document_id'],
            'coding_system' => 'ICD-10',
            'code' => 'J06.9',
            'display' => 'Acute upper respiratory infection, unspecified',
            'assertion_type' => 'initial',
        ], (string) $clinician->id);

        $fact = $published[0] ?? [];
        foreach ([
            'assertion_id', 'lineage_id', 'revision', 'document_id', 'registration_id', 'patient_id',
            'coding_system', 'code', 'display', 'assertion_type', 'supersedes_assertion_id',
            'note', 'asserted_by', 'asserted_by_name', 'asserted_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $fact);
        }

        $this->assertSame('J06.9', $fact['code']);
        $this->assertSame('initial', $fact['assertion_type']);
        $this->assertSame($signed['patient_id'], $fact['patient_id']);

        // Scalars only. A model would make the payload unserialisable for a
        // queued consumer and would leak this module's storage shape.
        foreach ($fact as $value) {
            $this->assertTrue($value === null || is_scalar($value));
        }
    }

    public function test_a_supersession_publishes_its_own_fact_naming_the_assertion_it_replaces(): void
    {
        $records = app(ActiveClinicalRecordContract::class);
        $clinician = User::factory()->create();
        $signed = $this->signedDocument($records, $clinician);
        $published = [];
        Event::listen(DiagnosisAssertionFactPublisher::EVENT, function (array $fact) use (&$published): void {
            $published[] = $fact;
        });

        $initial = $records->assertDiagnosis([
            'document_id' => $signed['document_id'],
            'coding_system' => 'ICD-10',
            'code' => 'J06.9',
            'display' => 'URTI',
            'assertion_type' => 'initial',
        ], (string) $clinician->id);
        $records->assertDiagnosis([
            'document_id' => $signed['document_id'],
            'coding_system' => 'ICD-10',
            'code' => 'J18.9',
            'display' => 'Pneumonia',
            'assertion_type' => 'supersession',
            'supersedes_assertion_id' => $initial['assertion_id'],
        ], (string) $clinician->id);

        $this->assertCount(2, $published);
        $this->assertNull($published[0]['supersedes_assertion_id']);
        $this->assertSame($initial['assertion_id'], $published[1]['supersedes_assertion_id']);
        // The first payload still reads as it did when it was published.
        $this->assertSame('J06.9', $published[0]['code']);
    }

    /** @return array<string, mixed> */
    private function signedDocument(ActiveClinicalRecordContract $records, User $clinician): array
    {
        $handoff = $records->acceptHandoff([
            'registration_id' => 'f3a5e0ad-4f0d-4795-8e5f-d62441b2a513',
            'patient_id' => 'e759fc76-5732-4fe1-a14d-a820cda7bbcb',
            'source_owner' => 'outpatient',
            'source_reference_id' => 'f0f4c31f-9c19-4d85-9a05-12f0725761db',
            'recipient_id' => (string) $clinician->id,
            'accepted_by' => (string) $clinician->id,
        ]);
        $draft = $records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => '2026-08-06T08:30:00+07:00',
            'payload' => ['subjective' => 'Cough and fever.'],
        ], (string) $clinician->id);

        return $records->signDocument($draft['document_id'], (string) $clinician->id);
    }
}
