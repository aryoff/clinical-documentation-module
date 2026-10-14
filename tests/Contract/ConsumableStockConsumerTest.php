<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Contracts\ConsumableStockPort;
use Modules\ClinicalDocumentation\Exceptions\ConsumableStockUnavailable;
use Tests\TestCase;

final class ConsumableStockConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_local_port_is_bound_in_every_composition(): void
    {
        $this->assertInstanceOf(ConsumableStockPort::class, app(ConsumableStockPort::class));
    }

    public function test_without_a_provider_every_operation_is_unavailable_and_nothing_falls_back(): void
    {
        if (app(CapabilityRegistry::class)->isResolved("pharmacy.consumable-stock")) {
            $this->markTestSkipped("Pharmacy is enabled in this composition.");
        }
        $port = app(ConsumableStockPort::class);

        $this->assertFalse($port->isAvailable());
        $this->expectException(ConsumableStockUnavailable::class);
        $port->consume([]);
    }

    public function test_the_port_forwards_to_a_resolved_provider_by_capability_id(): void
    {
        $registry = app(CapabilityRegistry::class);
        $bindings = $registry->providerBindings("pharmacy.consumable-stock");
        if ($bindings === [] || !$registry->isResolved("pharmacy.consumable-stock")) {
            $this->markTestSkipped("Pharmacy is not enabled in this composition.");
        }
        $this->app->instance($bindings[0], new class {
            public function consume(array $use): array
            {
                return ["movement_id" => "m-1", "correlation_id" => $use["use_id"], "replayed" => false];
            }
        });

        $port = app(ConsumableStockPort::class);

        $this->assertTrue($port->isAvailable());
        $this->assertSame("m-1", $port->consume(["use_id" => "u-1"])["movement_id"]);
    }

    public function test_an_answer_without_a_movement_identity_is_refused(): void
    {
        $registry = app(CapabilityRegistry::class);
        $bindings = $registry->providerBindings("pharmacy.consumable-stock");
        if ($bindings === [] || !$registry->isResolved("pharmacy.consumable-stock")) {
            $this->markTestSkipped("Pharmacy is not enabled in this composition.");
        }
        $this->app->instance($bindings[0], new class {
            public function consume(array $use): array
            {
                return ["correlation_id" => $use["use_id"], "replayed" => false];
            }
        });

        $this->expectException(\UnexpectedValueException::class);
        app(ConsumableStockPort::class)->consume(["use_id" => "u-1"]);
    }
}
