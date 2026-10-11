<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Support;

use Modules\ClinicalDocumentation\Models\PatientReassignment;

/** Resolve immutable point-in-time evidence through this context's identity history. */
final class ClinicalPatientIdentity
{
    /** @return array{patient_id: string, source_patient_ids: list<string>} */
    public function resolve(string $patient_id): array
    {
        $seen = [];
        while (
            ($successor = PatientReassignment::query()
                ->where("superseded_patient_id", $patient_id)
                ->latest("reconciled_at")
                ->value("canonical_patient_id")) !== null
        ) {
            if (isset($seen[$patient_id])) {
                throw new \LogicException(
                    "Patient reconciliation history contains a cycle.",
                );
            }
            $seen[$patient_id] = true;
            $patient_id = $successor;
        }
        $source_patient_ids = [$patient_id];
        $frontier = [$patient_id];
        while ($frontier !== []) {
            $frontier = array_values(
                array_diff(
                    PatientReassignment::query()
                        ->whereIn("canonical_patient_id", $frontier)
                        ->pluck("superseded_patient_id")
                        ->all(),
                    $source_patient_ids,
                ),
            );
            $source_patient_ids = array_merge($source_patient_ids, $frontier);
        }

        return [
            "patient_id" => $patient_id,
            "source_patient_ids" => array_values(
                array_unique($source_patient_ids),
            ),
        ];
    }
}
