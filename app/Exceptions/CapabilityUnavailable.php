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
}
