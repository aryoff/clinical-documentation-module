<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services\Capabilities;

use Modules\ClinicalDocumentation\Contracts\HospitalRegistrationPort;
use Modules\ClinicalDocumentation\Support\CapabilityGateway;

/** Optional Hospital Registration capability used by evidence staging. */
final class CapabilityHospitalRegistration implements HospitalRegistrationPort
{
    public const CAPABILITY_ID = 'hospitalcore.hospital-registration';

    public function __construct(private readonly CapabilityGateway $gateway) {}

    /** @return array<string, mixed>|null */
    public function describe(string $registrationId): ?array
    {
        $registration = $this->gateway->callIfAvailable(self::CAPABILITY_ID, 'describe', [$registrationId]);

        return is_array($registration) ? $registration : null;
    }

    /** @return array<string, mixed>|null */
    public function assertActive(string $registrationId): ?array
    {
        $registration = $this->gateway->callIfAvailable(self::CAPABILITY_ID, 'assertActive', [$registrationId]);

        return is_array($registration) ? $registration : null;
    }
}
