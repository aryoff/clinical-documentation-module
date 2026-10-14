<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * This module's view of the custodian of Medical Consumables.
 *
 * It names only what a Clinical Consumable Usage performs. Absence is a supported
 * state that makes consumable use unavailable; it is never answered with a local
 * balance, so every operation raises ConsumableStockUnavailable without a provider.
 */
interface ConsumableStockPort
{
    public function isAvailable(): bool;

    /** @return array<string, mixed> */
    public function consume(array $use): array;

    /** @return array<string, mixed> */
    public function correct(array $correction): array;

    /** @return array<string, mixed> */
    public function dispose(array $waste): array;
}
