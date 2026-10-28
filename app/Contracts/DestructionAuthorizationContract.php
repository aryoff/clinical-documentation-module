<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * Whether ONE destruction step is authorized right now. Asked by every custodian at the start of each irreversible step.
 *
 * An approval is bound to the exact set of records, the schedule version, the visit-history version and the hold state its
 * approver reviewed. A step outside that set, or after any of those changed, is not permitted, and the standing of the
 * remaining steps ends. Absence of an answer is never permission.
 */
interface DestructionAuthorizationContract
{
    /** @return array{permitted: bool, reason?: string|null} */
    public function permits(string $authorizationId, string $patientId, string $custodian, string $refType, string $refId): array;
}
