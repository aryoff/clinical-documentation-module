<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Exceptions\CapabilityUnavailable;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Services\DestructionCustody;
use Tests\TestCase;

/**
 * Consumer contract test for the optional `diagnostics.record-custody` `^1.0`.
 *
 * Absence of the diagnostic custodian must never read as "it holds nothing", and an answer that cannot say everything is incomplete custody.
 */
class DiagnosticsRecordCustodyConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY = 'diagnostics.record-custody';

    public function test_the_module_declares_this_optional_consumption(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')), true, 512, JSON_THROW_ON_ERROR);
        $consumer = collect($manifest['capabilities']['consumes'])->firstWhere('id', self::CAPABILITY);

        $this->assertNotNull($consumer);
        $this->assertSame('^1.0', $consumer['version']);
        $this->assertSame('sync', $consumer['mode']);
        $this->assertFalse($consumer['required']);
        $this->assertFileExists(base_path('Modules/ClinicalDocumentation/'.$consumer['consumerContractTest']));
        $this->assertNull(collect($manifest['capabilities']['consumes'])->firstWhere('id', 'laboratory.record-custody'), 'a specialty is reached through Diagnostics, never directly');
    }

    public function test_availability_follows_whether_a_provider_is_composed(): void
    {
        // Composed means a provider that is installed and booted: a module that is on disk but disabled is not one.
        $composed = collect(app(CapabilityRegistry::class)->providerBindings(self::CAPABILITY))->contains(fn (string $binding): bool => $this->app->bound($binding));

        $this->assertSame($composed, app(DestructionCustody::class)->available());
    }

    /** @param array<string, mixed> $answer */
    private function provider(array $answer): void
    {
        $bindings = app(CapabilityRegistry::class)->providerBindings(self::CAPABILITY);
        if ($bindings === []) {
            $this->markTestSkipped('Diagnostics is not part of this composition.');
        }
        $this->app->instance($bindings[0], new class($answer)
        {
            /** @param array<string, mixed> $answer */
            public function __construct(private readonly array $answer) {}

            /** @return array<string, mixed> */
            public function inventory(string $patientId): array
            {
                return $this->answer + ['patient_id' => $patientId];
            }

            /** @param array<string, mixed> $step @return array<string, mixed> */
            public function destroy(array $step): array
            {
                return ['receipt_id' => 'r-'.$step['custodian'].'-'.$step['ref_id'], 'seen' => $step];
            }
        });
    }

    public function test_a_composed_provider_is_asked_for_the_inventory_and_its_items_keep_their_custodian(): void
    {
        $this->provider(['complete' => true, 'blockers' => [], 'fingerprint' => 'f', 'items' => [
            ['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'state' => 'live'],
            ['custodian' => 'diagnostics', 'ref_type' => 'critical_notice_content', 'ref_id' => 'notice-1', 'state' => 'destroyed'],
        ]]);

        $inventory = app(DestructionCustody::class)->inventory('patient-1');

        $this->assertTrue($inventory['complete']);
        $this->assertSame([
            ['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'state' => 'live'],
            ['custodian' => 'diagnostics', 'ref_type' => 'critical_notice_content', 'ref_id' => 'notice-1', 'state' => 'destroyed'],
        ], $inventory['items']);
    }

    public function test_an_inventory_that_is_not_complete_says_so_and_names_the_blockers(): void
    {
        $this->provider(['complete' => false, 'blockers' => [['custodian' => 'laboratory', 'reason' => 'specialty_not_registered']], 'fingerprint' => 'f', 'items' => []]);

        $inventory = app(DestructionCustody::class)->inventory('patient-1');

        $this->assertFalse($inventory['complete']);
        $this->assertSame([['custodian' => 'laboratory', 'reason' => 'specialty_not_registered']], $inventory['blockers']);
    }

    public function test_an_answer_that_is_not_in_the_documented_shape_is_incomplete_never_empty(): void
    {
        $valid = ['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'state' => 'live'];
        foreach ([['items' => []], ['complete' => true], ['complete' => 'yes', 'items' => []], ['complete' => true, 'items' => 'none'], ['complete' => true, 'items' => ['first' => $valid]], ['complete' => true, 'items' => [['ref_id' => ''] + $valid]], ['complete' => true, 'items' => [['custodian' => ''] + $valid]]] as $answer) {
            $this->provider($answer);

            $inventory = app(DestructionCustody::class)->inventory('patient-1');

            $this->assertFalse($inventory['complete']);
            $this->assertSame([], $inventory['items']);
        }
    }

    public function test_an_item_that_is_not_typed_identified_and_stated_makes_the_inventory_incomplete_not_a_crash(): void
    {
        $this->provider(['complete' => true, 'blockers' => [], 'fingerprint' => 'f', 'items' => [
            ['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'state' => 'live'],
            ['custodian' => 'laboratory', 'ref_id' => 'line-2', 'state' => 'live'],
            'not an item',
            ['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'line-3', 'state' => 'maybe'],
            ['custodian' => 'laboratory', 'ref_type' => 'result_version', 'ref_id' => 'v-4', 'state' => 'live'],
        ]]);

        $inventory = app(DestructionCustody::class)->inventory('patient-1');

        $this->assertFalse($inventory['complete']);
        $this->assertSame([['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'state' => 'live']], $inventory['items']);
        $this->assertContains('custody_malformed', array_column($inventory['blockers'], 'reason'));
    }

    public function test_a_specialtys_copies_are_followed_to_this_modules_own_projection_by_result_reference(): void
    {
        $reference = (string) \Illuminate\Support\Str::uuid();
        $patient = (string) \Illuminate\Support\Str::uuid();
        $this->provider(['complete' => true, 'blockers' => [], 'fingerprint' => 'f', 'items' => [
            ['custodian' => 'laboratory', 'ref_type' => 'result_version', 'ref_id' => 'v-1', 'state' => 'live', 'result_reference_id' => $reference, 'version' => 1],
        ]]);
        $evidence = DiagnosticResultEvidence::query()->create([
            'patient_id' => $patient, 'registration_id' => (string) \Illuminate\Support\Str::uuid(), 'source_owner' => 'laboratory',
            'result_reference_id' => $reference, 'coding_system' => 'x', 'code' => 'K', 'display' => 'Potassium', 'summary' => '7.1', 'observed_at' => now(), 'released_by' => (string) \Illuminate\Support\Str::uuid(), 'released_by_name' => 'Releaser',
        ]);

        DiagnosticResultEvidence::query()->create([
            'patient_id' => (string) \Illuminate\Support\Str::uuid(), 'registration_id' => (string) \Illuminate\Support\Str::uuid(), 'source_owner' => 'laboratory',
            'result_reference_id' => (string) \Illuminate\Support\Str::uuid(), 'coding_system' => 'x', 'code' => 'K', 'display' => 'Potassium', 'summary' => '9.9', 'observed_at' => now(), 'released_by' => (string) \Illuminate\Support\Str::uuid(), 'released_by_name' => 'Releaser',
        ]);

        $items = app(DestructionCustody::class)->inventory($patient)['items'];

        $this->assertContains(['custodian' => 'clinicaldocumentation', 'ref_type' => 'diagnostic_result_evidence', 'ref_id' => $evidence->id, 'state' => 'live'], $items);
        $this->assertCount(1, array_filter($items, fn (array $i): bool => $i['custodian'] === 'clinicaldocumentation'), 'another patient\'s projection is not this patient\'s (a source version is projected once, so it can never share a reference)');
    }

    public function test_a_step_goes_to_the_custodian_named_with_its_custodian_and_returns_its_receipt(): void
    {
        $this->provider(['complete' => true, 'blockers' => [], 'fingerprint' => 'f', 'items' => []]);

        $receipt = app(DestructionCustody::class)->destroy('laboratory', ['authorization_id' => 'a', 'patient_id' => 'p', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'actor_id' => 'u']);

        $this->assertSame(['receipt_id' => 'r-laboratory-line-1'], $receipt);
    }

    public function test_a_custodian_that_is_not_composed_has_nothing_to_answer_and_is_not_asked_a_step(): void
    {
        $bindings = app(CapabilityRegistry::class)->providerBindings(self::CAPABILITY);
        if ($bindings === []) {
            $this->markTestSkipped('Diagnostics is not part of this composition.');
        }
        // Its module is disabled: the capability still names a binding, but nothing is booted behind it.
        foreach ($bindings as $binding) {
            unset($this->app[$binding]);
        }

        $inventory = app(DestructionCustody::class)->inventory((string) \Illuminate\Support\Str::uuid());

        $this->assertFalse(app(DestructionCustody::class)->available());
        $this->assertSame([[], [], true], [$inventory['items'], $inventory['blockers'], $inventory['complete']], 'a deployment that never had one holds nothing for it; whether that matters is available()');
    }

    public function test_without_a_provider_no_receipt_is_invented_and_nothing_reads_as_held_nothing(): void
    {
        if (app(CapabilityRegistry::class)->providerBindings(self::CAPABILITY) !== []) {
            $this->markTestSkipped('Diagnostics is composed here.');
        }

        $this->assertFalse(app(DestructionCustody::class)->available());
        $this->expectException(CapabilityUnavailable::class);

        app(DestructionCustody::class)->destroy('laboratory', ['authorization_id' => 'a', 'patient_id' => 'p', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'actor_id' => 'u']);
    }
}
