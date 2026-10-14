<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Exceptions\ConsumableStockUnavailable;
use Modules\ClinicalDocumentation\Models\ClinicalConsumableEvent;
use Modules\ClinicalDocumentation\Services\ClinicalConsumableUsageService;
use Modules\ClinicalDocumentation\Tests\Support\CredentialsClinicalActors;
use Modules\ClinicalDocumentation\Tests\Support\FakesConsumableCustody;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ClinicalConsumableUsageTest extends TestCase
{
    use CredentialsClinicalActors;
    use RefreshDatabase;

    use FakesConsumableCustody;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installConsumableFakes();
    }

    private function service(): ClinicalConsumableUsageService
    {
        return app(ClinicalConsumableUsageService::class);
    }

    private function nurse(string ...$abilities): User
    {
        $user = User::factory()->create();
        foreach ($abilities as $ability) {
            $user->givePermissionTo(Permission::findOrCreate($ability));
        }
        return $user;
    }

    private function use(array $changes = []): array
    {
        return [
            "idempotency_key" => (string) Str::uuid(),
            "registration_id" => $this->registration,
            "profile_id" => (string) Str::uuid(),
            "product_id" => (string) Str::uuid(),
            "batch_number" => "B-1",
            "expiry_date" => now()->addYear()->toDateString(),
            "quantity" => "2.000",
            ...$changes,
        ];
    }

    public function test_an_order_has_no_stock_effect(): void
    {
        $this->grantClinicalAbility($doctor = User::factory()->create(), "clinicaldocumentation.consumables.order");

        $event = $this->service()->order(
            ["idempotency_key" => (string) Str::uuid(), "registration_id" => $this->registration, "product_id" => (string) Str::uuid(), "quantity" => "2.000"],
            $doctor,
        );

        $this->assertSame("ordered", $event->type);
        $this->assertNull($event->movement_id);
        $this->assertSame([], $this->stock->calls);
    }

    public function test_actual_use_asks_pharmacy_to_consume_under_its_own_correlation(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");
        $input = $this->use();

        $event = $this->service()->record($input, $nurse);

        $this->assertSame("recorded", $event->type);
        [$method, $sent] = $this->stock->calls[0];
        $this->assertSame("consume", $method);
        $this->assertSame($event->use_id, $sent["use_id"]);
        $this->assertSame("clinical-documentation", $sent["source"]);
        $this->assertSame($this->registration, $sent["registration_id"]);
        $this->assertSame($nurse->id, $sent["actor_id"]);
        $this->assertNotNull($event->movement_id);
        $this->assertSame("11111111-1111-4111-8111-111111111111", $event->patient_id);
    }

    public function test_an_exact_retry_is_one_use_and_one_stock_call_per_attempt_key(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");
        $input = $this->use();

        $first = $this->service()->record($input, $nurse);
        $retry = $this->service()->record($input, $nurse);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, ClinicalConsumableEvent::where("type", "recorded")->count());
        $this->assertCount(1, $this->stock->calls);
    }

    public function test_it_records_nothing_when_pharmacy_is_unavailable(): void
    {
        $this->stock->available = false;
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");

        try {
            $this->service()->record($this->use(), $nurse);
            $this->fail("Use must be unavailable without the custodian.");
        } catch (ConsumableStockUnavailable) {
            $this->assertSame(0, ClinicalConsumableEvent::count());
        }
    }

    public function test_it_records_nothing_when_pharmacy_refuses_the_stock(): void
    {
        $this->stock->refuse = ValidationException::withMessages(["quantity" => "The profile has insufficient available stock in this batch."]);
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");

        try {
            $this->service()->record($this->use(), $nurse);
            $this->fail("A refused consumption must not leave a use fact.");
        } catch (ValidationException) {
            $this->assertSame(0, ClinicalConsumableEvent::count());
        }
    }

    public function test_it_refuses_a_registration_that_is_not_active(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");

        try {
            $this->service()->record($this->use(["registration_id" => (string) Str::uuid()]), $nurse);
            $this->fail("An unknown registration cannot be charged a use.");
        } catch (ValidationException) {
            $this->assertSame([], $this->stock->calls);
            $this->assertSame(0, ClinicalConsumableEvent::count());
        }
    }

    public function test_a_correction_cites_the_use_and_a_distinct_approver(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record", "clinicaldocumentation.consumables.correct");
        $approver = $this->nurse("clinicaldocumentation.consumables.correct");
        $used = $this->service()->record($this->use(), $nurse);

        $event = $this->service()->correct(
            ["idempotency_key" => (string) Str::uuid(), "use_id" => $used->use_id, "approver_id" => $approver->id, "reason" => "Wrong batch scanned"],
            $nurse,
        );

        $this->assertSame("corrected", $event->type);
        $this->assertSame($approver->name, $event->payload["approver_name"]);
        $this->assertSame($used->use_id, $event->use_id);
        [$method, $sent] = end($this->stock->calls);
        $this->assertSame("correct", $method);
        $this->assertSame($approver->id, $sent["approver_id"]);
    }

    public function test_a_use_is_corrected_once_and_only_after_it_was_recorded(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record", "clinicaldocumentation.consumables.correct");
        $approver = $this->nurse("clinicaldocumentation.consumables.correct");
        $correction = fn(string $use): array => ["idempotency_key" => (string) Str::uuid(), "use_id" => $use, "approver_id" => $approver->id, "reason" => "Wrong batch scanned"];

        try {
            $this->service()->correct($correction((string) Str::uuid()), $nurse);
            $this->fail("There is nothing to correct.");
        } catch (ValidationException) {
        }
        $used = $this->service()->record($this->use(), $nurse);
        $this->service()->correct($correction($used->use_id), $nurse);

        $this->expectException(ValidationException::class);
        $this->service()->correct($correction($used->use_id), $nurse);
    }

    public function test_waste_removes_the_used_batch_without_a_second_use(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");
        $used = $this->service()->record($this->use(), $nurse);

        $event = $this->service()->waste(
            ["idempotency_key" => (string) Str::uuid(), "use_id" => $used->use_id, "quantity" => "1.000", "reason" => "contaminated"],
            $nurse,
        );

        $this->assertSame("wasted", $event->type);
        [$method, $sent] = end($this->stock->calls);
        $this->assertSame("dispose", $method);
        $this->assertSame($used->payload["batch_number"], $sent["batch_number"]);
        $this->assertSame($used->payload["profile_id"], $sent["profile_id"]);
        $this->assertSame(1, ClinicalConsumableEvent::where("type", "recorded")->count());
    }

    public function test_a_use_may_fulfil_an_order_only_for_the_item_ordered(): void
    {
        $this->grantClinicalAbility($doctor = User::factory()->create(), "clinicaldocumentation.consumables.order");
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");
        $input = $this->use();
        $order = $this->service()->order(
            ["idempotency_key" => (string) Str::uuid(), "registration_id" => $this->registration, "product_id" => $input["product_id"], "quantity" => "4.000"],
            $doctor,
        );

        $first = $this->service()->record([...$input, "order_id" => $order->use_id], $nurse);
        $second = $this->service()->record(
            [...$this->use(["product_id" => $input["product_id"]]), "order_id" => $order->use_id],
            $nurse,
        );

        $this->assertSame($order->use_id, $first->payload["order_id"]);
        $this->assertNotSame($first->use_id, $second->use_id);
        $this->expectException(ValidationException::class);
        $this->service()->record(
            [...$this->use(["product_id" => (string) Str::uuid()]), "order_id" => $order->use_id],
            $nurse,
        );
    }

    public function test_the_item_the_custodian_names_is_kept_beside_its_id(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");

        $event = $this->service()->record($this->use(), $nurse);

        $this->assertSame(
            ["code" => "GZ-1", "name" => "Gauze", "unit" => "pcs"],
            $event->payload["item"],
        );
    }

    public function test_a_retry_spelled_in_uppercase_is_the_same_command(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");
        $input = $this->use();
        $first = $this->service()->record($input, $nurse);

        $retry = $this->service()->record(
            [
                ...$input,
                "idempotency_key" => strtoupper($input["idempotency_key"]),
                "registration_id" => strtoupper($input["registration_id"]),
                "profile_id" => strtoupper($input["profile_id"]),
                "product_id" => strtoupper($input["product_id"]),
            ],
            $nurse,
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertCount(1, $this->stock->calls);
    }

    public function test_a_second_person_repeating_a_key_is_refused_not_answered_with_the_first_record(): void
    {
        $nurse = $this->nurse("clinicaldocumentation.consumables.record");
        $other = $this->nurse("clinicaldocumentation.consumables.record");
        $input = $this->use();
        $this->service()->record($input, $nurse);

        try {
            $this->service()->record($input, $other);
            $this->fail("Another person's identical command is not a retry.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey("idempotency_key", $exception->errors());
            $this->assertSame(1, ClinicalConsumableEvent::count());
        }
    }

    public function test_a_correction_cannot_be_approved_by_the_same_person_under_another_spelling(): void
    {
        $nurse = $this->nurse(
            "clinicaldocumentation.consumables.record",
            "clinicaldocumentation.consumables.correct",
        );
        $used = $this->service()->record($this->use(), $nurse);

        $this->expectException(ValidationException::class);
        $this->service()->correct(
            [
                "idempotency_key" => (string) Str::uuid(),
                "use_id" => $used->use_id,
                "approver_id" => strtoupper((string) $nurse->id),
                "reason" => "Wrong batch scanned",
            ],
            $nurse,
        );
    }
}
