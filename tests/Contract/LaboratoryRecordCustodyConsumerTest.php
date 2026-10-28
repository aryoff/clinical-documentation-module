<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Services\DestructionCustody;
use Tests\TestCase;

/**
 * Consumer contract test for the optional `laboratory.record-custody` `^1.0`.
 *
 * Absence of Laboratory must never read as "Laboratory holds nothing": it is reported as unavailable.
 */
class LaboratoryRecordCustodyConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_module_declares_this_optional_consumption(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')), true, 512, JSON_THROW_ON_ERROR);
        $consumer = collect($manifest['capabilities']['consumes'])->firstWhere('id', 'laboratory.record-custody');

        $this->assertNotNull($consumer);
        $this->assertSame('^1.0', $consumer['version']);
        $this->assertSame('sync', $consumer['mode']);
        $this->assertFalse($consumer['required']);
    }

    public function test_availability_follows_whether_a_provider_is_composed(): void
    {
        $composed = app(CapabilityRegistry::class)->providerBindings('laboratory.record-custody') !== [];

        $this->assertSame($composed, app(DestructionCustody::class)->laboratoryAvailable());
    }

    public function test_a_composed_provider_is_asked_for_the_inventory(): void
    {
        $bindings = app(CapabilityRegistry::class)->providerBindings('laboratory.record-custody');
        if ($bindings === []) {
            $this->markTestSkipped('Laboratory is not part of this composition.');
        }
        $this->app->instance($bindings[0], new class
        {
            /** @return array<string, mixed> */
            public function inventory(string $patientId): array
            {
                return ['patient_id' => $patientId, 'fingerprint' => 'f', 'items' => [['ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'state' => 'live']]];
            }
        });

        $items = app(DestructionCustody::class)->inventory('patient-1');

        $this->assertSame([['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'line-1', 'state' => 'live']], $items);
    }
}
