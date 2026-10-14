<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Support\CapabilityRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Contracts\HospitalRegistrationPort;
use Modules\ClinicalDocumentation\Models\ClinicalConsumableEvent;
use Modules\ClinicalDocumentation\Services\ClinicalConsumableUsageService;
use Modules\Pharmacy\Models\LocalStockMovement;
use Modules\Pharmacy\Services\LocalStockLedger;
use Modules\Pharmacy\Tests\Support\ConsumableStockFixture;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** The real custodian behind the real port: one use fact, one movement, one correlation. */
final class ClinicalConsumablePharmacyChainTest extends TestCase
{
    private string $registration;

    protected function setUp(): void
    {
        parent::setUp();
        if (!app(CapabilityRegistry::class)->isResolved("pharmacy.consumable-stock")) {
            $this->markTestSkipped("Pharmacy is not part of this composition.");
        }
        $this->registration = (string) Str::uuid();
        $registration = $this->registration;
        $this->app->instance(HospitalRegistrationPort::class, new class($registration) implements HospitalRegistrationPort {
            public function __construct(private string $id) {}
            public function describe(string $registrationId): ?array
            {
                return $registrationId === $this->id
                    ? ["registration_id" => $this->id, "patient_id" => (string) Str::uuid(), "journey_status" => "active"]
                    : null;
            }
            public function assertActive(string $registrationId): ?array
            {
                return $this->describe($registrationId);
            }
        });
    }

    private function balance(array $fixture): string
    {
        return app(LocalStockLedger::class)->balances($fixture["profile"]->id)[0]["quantity"];
    }

    private function input(array $fixture): array
    {
        return [
            "idempotency_key" => (string) Str::uuid(),
            "registration_id" => $this->registration,
            "profile_id" => $fixture["use"]["profile_id"],
            "product_id" => $fixture["use"]["product_id"],
            "batch_number" => $fixture["use"]["batch_number"],
            "expiry_date" => $fixture["use"]["expiry_date"],
            "quantity" => "2.000",
        ];
    }

    public function test_a_use_correction_and_waste_share_one_correlation_with_the_stock_movements(): void
    {
        $fixture = ConsumableStockFixture::create();
        $nurse = $fixture["actor"];
        $nurse->givePermissionTo([Permission::findOrCreate(ClinicalConsumableUsageService::RECORD_PERMISSION), Permission::findOrCreate(ClinicalConsumableUsageService::CORRECT_PERMISSION)]);
        $service = app(ClinicalConsumableUsageService::class);

        $used = $service->record($this->input($fixture), $nurse);
        $this->assertSame("4.000", $this->balance($fixture));
        $movement = LocalStockMovement::findOrFail($used->movement_id);
        $this->assertSame($used->use_id, $movement->correlation_id);
        $this->assertSame($this->registration, $movement->registration_id);

        $waste = $service->waste(
            ["idempotency_key" => (string) Str::uuid(), "use_id" => $used->use_id, "quantity" => "1.000", "reason" => "contaminated"],
            $nurse,
        );
        $this->assertSame("3.000", $this->balance($fixture));
        $this->assertSame($used->use_id, LocalStockMovement::findOrFail($waste->movement_id)->correlation_id);

        $correction = $service->correct(
            ["idempotency_key" => (string) Str::uuid(), "use_id" => $used->use_id, "approver_id" => $fixture["approver"]->id, "reason" => "Wrong patient"],
            $nurse,
        );
        $this->assertSame("5.000", $this->balance($fixture));
        $this->assertSame(
            $used->movement_id,
            LocalStockMovement::findOrFail($correction->movement_id)->reverses_movement_id,
        );
        $this->assertSame(3, ClinicalConsumableEvent::where("use_id", $used->use_id)->count());
    }

    public function test_a_custodian_answer_without_a_movement_leaves_no_use_fact_behind(): void
    {
        $fixture = ConsumableStockFixture::create();
        $nurse = $fixture["actor"];
        $nurse->givePermissionTo(Permission::findOrCreate(ClinicalConsumableUsageService::RECORD_PERMISSION));
        $binding = app(CapabilityRegistry::class)->providerBindings("pharmacy.consumable-stock")[0];
        $this->app->instance($binding, new class {
            public function consume(array $use): array
            {
                return ["correlation_id" => $use["use_id"], "replayed" => false];
            }
        });

        try {
            app(ClinicalConsumableUsageService::class)->record($this->input($fixture), $nurse);
            $this->fail("A provider that names no movement is broken.");
        } catch (\UnexpectedValueException) {
            $this->assertSame(0, ClinicalConsumableEvent::count());
        }
    }

    public function test_the_stock_refusal_leaves_no_use_fact_behind(): void
    {
        $fixture = ConsumableStockFixture::create();
        $nurse = $fixture["actor"];
        $nurse->givePermissionTo([Permission::findOrCreate(ClinicalConsumableUsageService::RECORD_PERMISSION), Permission::findOrCreate(ClinicalConsumableUsageService::CORRECT_PERMISSION)]);

        try {
            app(ClinicalConsumableUsageService::class)->record([...$this->input($fixture), "quantity" => "9.000"], $nurse);
            $this->fail("The profile does not hold nine units.");
        } catch (ValidationException) {
            $this->assertSame(0, ClinicalConsumableEvent::count());
            $this->assertSame("6.000", $this->balance($fixture));
        }
    }
}
