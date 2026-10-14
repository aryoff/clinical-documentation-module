<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services\Capabilities;

use Modules\ClinicalDocumentation\Contracts\ConsumableStockPort;
use Modules\ClinicalDocumentation\Exceptions\ConsumableStockUnavailable;
use Modules\ClinicalDocumentation\Support\CapabilityGateway;

/** Optional pharmacy.consumable-stock capability, reached by ID. */
final class CapabilityConsumableStock implements ConsumableStockPort
{
    public const CAPABILITY_ID = "pharmacy.consumable-stock";

    public function __construct(private readonly CapabilityGateway $gateway) {}

    public function isAvailable(): bool
    {
        return $this->gateway->isAvailable(self::CAPABILITY_ID);
    }

    public function consume(array $use): array
    {
        return $this->invoke("consume", $use);
    }

    public function correct(array $correction): array
    {
        return $this->invoke("correct", $correction);
    }

    public function dispose(array $waste): array
    {
        return $this->invoke("dispose", $waste);
    }

    /** @return array<string, mixed> */
    private function invoke(string $method, array $arguments): array
    {
        $answer = $this->gateway->callIfAvailable(self::CAPABILITY_ID, $method, [$arguments]);
        if (!is_array($answer)) {
            throw new ConsumableStockUnavailable();
        }
        // A use fact must always point at the movement behind it; an answer without one is a broken provider.
        if (!is_string($answer["movement_id"] ?? null) || $answer["movement_id"] === "") {
            throw new \UnexpectedValueException(
                "The consumable custodian answered without a movement identity.",
            );
        }

        return $answer;
    }
}
