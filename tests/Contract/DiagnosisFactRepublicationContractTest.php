<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Contracts\DiagnosisFactRepublicationContract;
use Modules\ClinicalDocumentation\Services\DiagnosisAssertionFactPublisher;
use Tests\TestCase;

class DiagnosisFactRepublicationContractTest extends TestCase
{
    public function test_one_assertion_is_republished_only_to_its_registered_requester_with_original_lineage(): void
    {
        $broadcasts = [];
        Event::listen(DiagnosisAssertionFactPublisher::EVENT, function (array $fact) use (&$broadcasts): void {
            $broadcasts[] = $fact;
        });
        [$initial, $successor] = $this->publishLineage();
        $received = [];
        $other = [];
        $capability = app(DiagnosisFactRepublicationContract::class);
        $capability->registerConsumer('requester', function (array $fact) use (&$received): void {
            $received[] = $fact;
        }, fn (): bool => true);
        $capability->registerConsumer('other', function (array $fact) use (&$other): void {
            $other[] = $fact;
        }, fn (): bool => true);
        $this->travelTo(CarbonImmutable::parse('2026-04-01T12:00:00+00:00'));
        $this->assertNull($capability->request($successor['assertion_id'], 'requester'));
        $this->assertSame([$broadcasts[1]], $received);
        $this->assertSame([], $other);
        $this->assertCount(2, $broadcasts, 'Republication must not reach the global event listeners.');
        $this->assertSame(2, $received[0]['revision']);
        $this->assertSame($initial['lineage_id'], $received[0]['lineage_id']);
        $this->assertSame($initial['assertion_id'], $received[0]['supersedes_assertion_id']);
        $this->assertSame('2026-03-05T11:00:00+00:00', $received[0]['asserted_at']);
        // A superseded assertion is historical evidence, not a retracted fact.
        $capability->request($initial['assertion_id'], 'requester');
        $this->assertSame($broadcasts[0], $received[1]);
        $this->travelBack();
    }

    public function test_unknown_unregistered_and_unauthorized_requests_are_indistinguishable_and_emit_nothing(): void
    {
        [$initial] = $this->publishLineage();
        $received = [];
        $allowed = true;
        $capability = app(DiagnosisFactRepublicationContract::class);
        $capability->registerConsumer('requester', function (array $fact) use (&$received): void {
            $received[] = $fact;
        }, function () use (&$allowed): bool {
            return $allowed;
        });
        $this->assertNull($capability->request((string) Str::uuid(), 'requester'));
        $this->assertNull($capability->request('malformed', 'requester'));
        $this->assertNull($capability->request($initial['assertion_id'], 'unregistered'));
        $allowed = false;
        $this->assertNull($capability->request($initial['assertion_id'], 'requester'));
        // Registration cannot replace a gate previously fixed by trusted bootstrap.
        $capability->registerConsumer('requester', function (array $fact) use (&$received): void {
            $received[] = $fact;
        }, fn (): bool => true);
        $this->assertNull($capability->request($initial['assertion_id'], 'requester'));
        $this->assertSame([], $received);
        $this->travelBack();
    }

    public function test_republication_uses_current_canonical_identity_and_preserves_the_original_fact_otherwise(): void
    {
        $broadcasts = [];
        Event::listen(DiagnosisAssertionFactPublisher::EVENT, function (array $fact) use (&$broadcasts): void {
            $broadcasts[] = $fact;
        });
        [$initial] = $this->publishLineage();
        $canonical = (string) Str::uuid();
        event('hospitalcore.patient-identity-reconciled', [[
            'canonical_patient_id' => $canonical, 'superseded_patient_id' => $initial['patient_id'],
            'reconciled_by' => (string) User::factory()->create()->id,
            'reason' => 'Verified canonical identity', 'reconciled_at' => now()->toAtomString(),
        ]]);
        $received = [];
        $capability = app(DiagnosisFactRepublicationContract::class);
        $capability->registerConsumer('requester', function (array $fact) use (&$received): void {
            $received[] = $fact;
        }, fn (): bool => true);
        $capability->request($initial['assertion_id'], 'requester');
        $expected = $broadcasts[0];
        $expected['patient_id'] = $canonical;
        $this->assertSame([$expected], $received);
        $this->travelBack();
    }

    public function test_the_separate_capability_declares_its_binding_version_contract_and_evidence(): void
    {
        $manifest = json_decode(file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')), true, 512, JSON_THROW_ON_ERROR);
        $provided = collect($manifest['capabilities']['provides'])->firstWhere('id', 'clinicaldocumentation.diagnosis-fact-republication');
        $this->assertSame('1.0.0', $provided['version']);
        $this->assertSame(['sync'], $provided['modes']);
        $this->assertInstanceOf(DiagnosisFactRepublicationContract::class, app($provided['binding']));
        $this->assertFileExists(base_path('Modules/ClinicalDocumentation/'.$provided['contracts']['sync']));
        $this->assertSame('tests/Contract/DiagnosisFactRepublicationContractTest.php', $provided['providerContractTest']);
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function publishLineage(): array
    {
        $records = app(ActiveClinicalRecordContract::class);
        $clinician = User::factory()->create();
        $handoff = $records->acceptHandoff([
            'registration_id' => (string) Str::uuid(), 'patient_id' => (string) Str::uuid(),
            'source_owner' => 'outpatient', 'source_reference_id' => (string) Str::uuid(),
            'recipient_id' => (string) $clinician->id, 'accepted_by' => (string) $clinician->id,
        ]);
        $draft = $records->createDraft([
            'handoff_id' => $handoff['handoff_id'], 'template' => 'soap', 'template_version' => '1.0.0',
            'encountered_at' => '2026-03-04T09:00:00+00:00', 'payload' => ['subjective' => 'Cough.'],
        ], (string) $clinician->id);
        $document = $records->signDocument($draft['document_id'], (string) $clinician->id);
        $this->travelTo(CarbonImmutable::parse('2026-03-04T10:00:00+00:00'));
        $initial = $records->assertDiagnosis([
            'document_id' => $document['document_id'], 'coding_system' => 'ICD-10',
            'code' => 'J06.9', 'display' => 'URTI', 'assertion_type' => 'initial',
        ], (string) $clinician->id);
        $this->travelTo(CarbonImmutable::parse('2026-03-05T11:00:00+00:00'));
        $successor = $records->assertDiagnosis([
            'document_id' => $document['document_id'], 'coding_system' => 'ICD-10',
            'code' => 'J18.9', 'display' => 'Pneumonia', 'assertion_type' => 'supersession',
            'supersedes_assertion_id' => $initial['assertion_id'],
        ], (string) $clinician->id);

        return [$initial, $successor];
    }
}
