<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Contracts\PatientLastVisitPort;
use Tests\TestCase;

/**
 * Consumer contract test for the optional `hospitalcore.patient-last-visit` `^1.0`.
 *
 * The port is always bound; whether it can answer depends on the composition. Absence must read as "cannot be
 * established", never as a patient without a visit.
 */
class PatientLastVisitConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_module_declares_this_optional_consumption(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')), true, 512, JSON_THROW_ON_ERROR);
        $consumer = collect($manifest['capabilities']['consumes'])->firstWhere('id', 'hospitalcore.patient-last-visit');

        $this->assertNotNull($consumer);
        $this->assertSame('^1.0', $consumer['version']);
        $this->assertSame('sync', $consumer['mode']);
        $this->assertFalse($consumer['required']);
    }

    public function test_the_local_port_is_bound_even_when_the_optional_provider_is_absent(): void
    {
        $this->assertInstanceOf(PatientLastVisitPort::class, app(PatientLastVisitPort::class));
    }

    public function test_the_local_port_forwards_to_a_resolved_provider(): void
    {
        $bindings = app(CapabilityRegistry::class)->providerBindings('hospitalcore.patient-last-visit');
        if ($bindings === []) {
            $this->markTestSkipped('HospitalCore is not part of this composition.');
        }

        $this->app->instance($bindings[0], new class
        {
            /** @return array<string, mixed> */
            public function patientLastVisit(string $patientId, string $asOf): array
            {
                return ['patient_id' => $patientId, 'last_visit_ended_at' => null, 'ongoing' => false, 'history_version' => 3, 'coverage' => ['complete' => false, 'through' => $asOf, 'blockers' => ['no_attendance_evidence']]];
            }
        });

        $answer = app(PatientLastVisitPort::class)->lastVisit('patient-1', '2026-01-01T00:00:00+00:00');

        $this->assertSame(3, $answer['history_version']);
        $this->assertSame(['no_attendance_evidence'], $answer['coverage']['blockers']);
    }
}
