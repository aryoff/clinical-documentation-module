<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * Publishes immutable diagnosis facts for asynchronous consumers.
 *
 * Integrations need the diagnosis, not the diagnosis owner's storage. They
 * receive a scalar snapshot at the moment it is asserted and keep their own
 * projection, so a delayed or retried external submission still carries the
 * clinical meaning that was true when the clinician asserted it.
 */
interface DiagnosisAssertionFactPublisher
{
    /** @param array<string, mixed> $fact */
    public function publish(array $fact): void;
}
