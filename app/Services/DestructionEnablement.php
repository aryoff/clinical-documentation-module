<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

/**
 * Whether destruction may execute in this deployment. One explicit switch, off by default; reporting (schedules,
 * holds, the review) never depends on it.
 */
final class DestructionEnablement
{
    public function enabled(): bool
    {
        return (bool) config('clinicaldocumentation.retention.destruction_enabled', false);
    }
}
