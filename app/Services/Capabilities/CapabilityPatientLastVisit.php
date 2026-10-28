<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services\Capabilities;

use Modules\ClinicalDocumentation\Contracts\PatientLastVisitPort;
use Modules\ClinicalDocumentation\Support\CapabilityGateway;

/** Optional Patient Last Visit capability, reached by ID so this module imports nothing from HospitalCore. */
final class CapabilityPatientLastVisit implements PatientLastVisitPort
{
    public const CAPABILITY_ID = 'hospitalcore.patient-last-visit';

    public function __construct(private readonly CapabilityGateway $gateway) {}

    /** {@inheritDoc} */
    public function lastVisit(string $patientId, string $asOf): ?array
    {
        $answer = $this->gateway->callIfAvailable(self::CAPABILITY_ID, 'patientLastVisit', [$patientId, $asOf]);

        return is_array($answer) ? $answer : null;
    }
}
