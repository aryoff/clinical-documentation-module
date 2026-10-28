<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * Whether ONE destruction step is allowed right now. Asked by every custodian at the start of each irreversible step.
 *
 * A step is permitted only for a destruction a person started, covering exactly that copy, while destruction is switched on and
 * nothing that allowed it has changed: the retention review still has no blocker, no retained assertion cites the content, no
 * unproven vaulted copy exists and no copy appeared that the person did not see. Absence of an answer is never permission.
 */
interface DestructionAuthorizationContract
{
    /** @return array{permitted: bool, reason?: string|null} */
    public function permits(string $authorizationId, string $patientId, string $custodian, string $refType, string $refId): array;
}
