<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * This module's view of the Hospital Registration capability.
 *
 * The port keeps ClinicalDocumentation installable without HospitalCore while
 * allowing the staging workflow to ask only whether a registration is active
 * and who the registration belongs to.
 */
interface HospitalRegistrationPort
{
    /** @return array<string, mixed>|null */
    public function describe(string $registrationId): ?array;

    /**
     * Return the active registration while retaining its provider-side row
     * lock until the caller's transaction commits.
     *
     * Consumers must call this from the transaction that persists their
     * registration-owned record.
     *
     * @return array<string, mixed>|null
     */
    public function assertActive(string $registrationId): ?array;
}
