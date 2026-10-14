<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Contracts\ConsumableStockPort;
use Modules\ClinicalDocumentation\Contracts\HospitalRegistrationPort;
use Modules\ClinicalDocumentation\Exceptions\ConsumableStockUnavailable;

/** An active registration and a recording custodian, so care-side rules are proven without either provider. */
trait FakesConsumableCustody
{
    private object $stock;
    private string $registration;

    private function installConsumableFakes(): void
    {
        $this->registration = (string) Str::uuid();
        $registration = $this->registration;
        $this->app->instance(HospitalRegistrationPort::class, new class($registration) implements HospitalRegistrationPort {
            public function __construct(private string $id) {}
            public function describe(string $registrationId): ?array
            {
                return $registrationId === $this->id
                    ? ["registration_id" => $this->id, "patient_id" => "11111111-1111-4111-8111-111111111111", "journey_status" => "active"]
                    : null;
            }
            public function assertActive(string $registrationId): ?array
            {
                return $this->describe($registrationId);
            }
        });
        $this->stock = new class implements ConsumableStockPort {
            public array $calls = [];
            public bool $available = true;
            public ?ValidationException $refuse = null;
            public function isAvailable(): bool
            {
                return $this->available;
            }
            public function consume(array $use): array
            {
                return $this->answer("consume", $use);
            }
            public function correct(array $correction): array
            {
                return $this->answer("correct", $correction);
            }
            public function dispose(array $waste): array
            {
                return $this->answer("dispose", $waste);
            }
            private function answer(string $method, array $args): array
            {
                if (!$this->available) {
                    throw new ConsumableStockUnavailable();
                }
                if ($this->refuse !== null) {
                    throw $this->refuse;
                }
                $this->calls[] = [$method, $args];
                return ["movement_id" => (string) Str::uuid(), "correlation_id" => $args["use_id"], "item_code" => "GZ-1", "item_name" => "Gauze", "unit" => "pcs", "replayed" => false];
            }
        };
        $this->app->instance(ConsumableStockPort::class, $this->stock);
    }
}
