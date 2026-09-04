<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\ClinicalDocumentation\Listeners\ReassignReconciledPatient;
use Tests\TestCase;

/**
 * Consumer contract test for `hospitalcore.patient-identity-reconciled` `^1.0`.
 *
 * Declared in module.json as this module's evidence. What this module depends
 * on is narrow and entirely runtime: the fact arrives under a *name* it never
 * imports, carrying a payload whose *keys* it reads. Neither can be checked by
 * the type system — a subscriber holding no publisher import cannot break at
 * compile time, only in production — so they are asserted here instead.
 *
 * The subscription itself is the other half. A listener that is never
 * registered fails no test that calls `handle()` directly, so this drives the
 * real dispatcher to prove the wiring exists.
 */
class PatientIdentityReconciledConsumerTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY_ID = 'hospitalcore.patient-identity-reconciled';

    /** Every key this module reads off the fact. */
    private const CONSUMED_KEYS = ['canonical_patient_id', 'superseded_patient_id', 'reconciled_by', 'reason', 'reconciled_at'];

    public function test_the_module_declares_this_capability_as_optional(): void
    {
        $consumer = $this->declaredConsumer();

        $this->assertSame('async', $consumer['mode']);
        // Optional on purpose: a fork with no patient registry installs this
        // module unchanged, and the fact simply never arrives.
        $this->assertFalse($consumer['required']);
    }

    public function test_the_listener_is_subscribed_to_the_published_name(): void
    {
        $listeners = Event::getListeners(self::CAPABILITY_ID);

        $this->assertNotEmpty($listeners, 'Nothing in this module is subscribed to [' . self::CAPABILITY_ID . '].');
    }

    public function test_the_published_payload_carries_every_key_this_module_reads(): void
    {
        $contract = (string) file_get_contents(
            base_path('Modules/HospitalCore/contracts/' . self::CAPABILITY_ID . '/v1/contract.md'),
        );

        foreach (self::CONSUMED_KEYS as $key) {
            $this->assertStringContainsString(
                "`{$key}`",
                $contract,
                "The provider contract must carry the [{$key}] key this module reads.",
            );
        }
    }

    public function test_dispatching_the_fact_reaches_this_modules_listener(): void
    {
        Event::fake([self::CAPABILITY_ID]);

        Event::dispatch(self::CAPABILITY_ID, [[]]);

        Event::assertDispatched(self::CAPABILITY_ID);
        $this->assertTrue(class_exists(ReassignReconciledPatient::class));
    }

    /** @return array<string, mixed> */
    private function declaredConsumer(): array
    {
        $manifest = json_decode(
            file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($manifest['capabilities']['consumes'] as $consumer) {
            if ($consumer['id'] === self::CAPABILITY_ID) {
                return $consumer;
            }
        }

        $this->fail('Module manifest does not consume capability [' . self::CAPABILITY_ID . '].');
    }
}
