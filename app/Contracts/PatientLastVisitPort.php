<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * This module's own port onto HospitalCore's `hospitalcore.patient-last-visit`, which is optional: a composition
 * without it answers null, and a retention review treats that as "cannot be established", never as "no visit".
 */
interface PatientLastVisitPort
{
    /**
     * @return array{patient_id: string, last_visit_ended_at: string|null, ongoing: bool, history_version: int, coverage: array{complete: bool, through: string, blockers: list<string>}}|null
     */
    public function lastVisit(string $patientId, string $asOf): ?array;
}
