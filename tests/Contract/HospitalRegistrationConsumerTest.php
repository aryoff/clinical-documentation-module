<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Contracts\HospitalRegistrationPort;
use Tests\TestCase;

class HospitalRegistrationConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_local_port_is_bound_even_when_the_optional_provider_is_absent(): void
    {
        $this->assertInstanceOf(HospitalRegistrationPort::class, app(HospitalRegistrationPort::class));
    }

    public function test_an_unknown_registration_is_not_described_as_active(): void
    {
        $this->assertNull(app(HospitalRegistrationPort::class)->describe((string) fake()->uuid()));
    }

    public function test_the_local_port_forwards_to_a_resolved_provider(): void
    {
        $bindings = app(CapabilityRegistry::class)->providerBindings('hospitalcore.hospital-registration');
        if ($bindings === []) {
            $this->markTestSkipped('HospitalCore is not part of this composition.');
        }

        $registrationId = (string) fake()->uuid();
        $binding = $bindings[0];
        $this->app->instance($binding, new class
        {
            /** @return array<string, mixed> */
            public function describe(string $registrationId): array
            {
                return [
                    'registration_id' => $registrationId,
                    'patient_id' => (string) fake()->uuid(),
                    'journey_status' => 'active',
                ];
            }

            /** @return array<string, mixed> */
            public function assertActive(string $registrationId): array
            {
                return $this->describe($registrationId);
            }
        });

        $port = app(HospitalRegistrationPort::class);
        $this->assertSame($registrationId, $port->describe($registrationId)['registration_id']);
        $this->assertSame($registrationId, $port->assertActive($registrationId)['registration_id']);
    }
}
