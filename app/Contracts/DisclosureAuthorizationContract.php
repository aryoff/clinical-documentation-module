<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * Whether a recorded Clinical Disclosure Request entitles its recipient to ONE exact result version right now.
 *
 * Entitlement comes from the basis actually verified (the patient's own access, their authorization, established
 * representative authority or a specific statutory basis), never from the recipient's relationship, a staff read permission,
 * a payment status or a chosen channel. Absence of an answer is never permission.
 */
interface DisclosureAuthorizationContract
{
    /**
     * Decide, and record the decision, for one exact version of one result lineage.
     *
     * @return array{authorized: bool, reason: string|null, authorization_id: string|null, basis: string|null, recipient_name: string|null, historical_copy: bool, request_id: string|null, ref_type?: string, ref_id?: string, lineage_id?: string}
     */
    public function authorize(string $requestId, string $patientId, string $refType, string $refId, string $lineageId, bool $isCurrentVersion): array;

    /**
     * Re-evaluate an earlier granted decision against what is true now (a consent withdrawn or expired since refuses it).
     *
     * @return array{authorized: bool, reason: string|null, authorization_id: string|null, basis: string|null, recipient_name: string|null, historical_copy: bool, request_id: string|null, ref_type?: string, ref_id?: string, lineage_id?: string}
     */
    public function recheck(string $authorizationId): array;
}
