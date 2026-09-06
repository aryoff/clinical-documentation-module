<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Exceptions;

use RuntimeException;

/** The enabled provider does not satisfy the operation its consumer needs. */
class CapabilityUnavailable extends RuntimeException
{
    public static function doesNotSupport(string $capabilityId, string $method): self
    {
        return new self("The enabled provider for capability [{$capabilityId}] does not support operation [{$method}].");
    }

    /**
     * A capability this module declares `required` has no enabled provider.
     *
     * Distinct from `doesNotSupport`, which is a contract disagreement with a
     * provider that *is* running. This one is a composition the analyzer should
     * have refused, and it raises rather than degrading because the operations
     * behind it — publishing an emergency access for review — have no safe
     * silent failure.
     */
    public static function required(string $capabilityId): self
    {
        return new self("Capability [{$capabilityId}] is required by ClinicalDocumentation and no enabled module provides it.");
    }
}
