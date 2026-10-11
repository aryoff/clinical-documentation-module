<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Listeners;

use Modules\ClinicalDocumentation\Models\AdministrationProjection;
use Illuminate\Support\Facades\Validator;

/** Receives only the versioned scalar snapshot; no source model or authoring permission. */
final class ProjectAdministrationClinicalFact
{
    public function handle(array $fact): void
    {
        $this->validate($fact);
        $projection = AdministrationProjection::firstOrCreate(
            [
                "source_reference" => $fact["source_reference"],
                "type" => $fact["type"],
            ],
            [
                "id" => $fact["fact_id"],
                "patient_id" => $fact["patient_id"],
                "registration_id" => $fact["registration_id"],
                "fact" => $fact,
                "occurred_at" => $fact["occurred_at"],
            ],
        );
        if ($this->canonical($projection->fact) !== $this->canonical($fact)) {
            throw new \DomainException(
                "A finalized administration projection cannot be replaced by a conflicting publication.",
            );
        }
    }
    private function validate(array $fact): void
    {
        $this->assertScalarMap($fact);
        $rules = [
            "fact_id" => ["required", "uuid"],
            "fact_version" => ["required", "string", 'regex:/^1\.\d+\.\d+$/'],
            "type" => ["required", "in:recorded,corrected,basis_reconciled"],
            "source_reference" => ["required", "string"],
            "administration_id" => ["required", "uuid"],
            "patient_id" => ["required", "uuid"],
            "registration_id" => ["required", "uuid"],
            "occurred_at" => ["required", "string", "date"],
            "basis" => ["required", "array"],
            "basis.type" => ["required", "string"],
        ];
        if (($fact["type"] ?? null) === "basis_reconciled") {
            $rules += [
                "reconciliation_id" => ["required", "uuid"],
                "administration" => ["required", "array"],
                "reconciled_by" => ["required", "array"],
                "reconciled_by.id" => ["required", "uuid"],
                "reconciled_by.name" => ["required", "string"],
                "reconciled_at" => ["required", "string", "date"],
            ];
        } else {
            $rules += [
                "medicine" => ["required", "array"],
                "medicine.product_name" => ["required", "string"],
                "dose" => ["present", "nullable", "array"],
                "outcome" => ["required", "string"],
                "route" => ["present", "nullable", "string"],
                "administered_at" => ["required", "string", "date"],
                "administered_by" => ["required", "array"],
                "administered_by.id" => ["required", "uuid"],
                "administered_by.name" => ["required", "string"],
                "care_location" => ["required", "array"],
                "care_location.id" => ["required", "uuid"],
                "care_location.name" => ["required", "string"],
                "verification" => ["present", "nullable", "array"],
            ];
            if (($fact["type"] ?? null) === "corrected") {
                $rules += [
                    "corrects_administration_id" => ["required", "uuid"],
                    "correction_reason" => ["required", "string"],
                ];
            }
        }
        $validation = Validator::make($fact, $rules);
        if ($validation->fails()) {
            throw new \InvalidArgumentException(
                "Invalid administration publication: " .
                    implode(" ", $validation->errors()->all()),
            );
        }
        if (
            version_compare($fact["fact_version"], "1.4.0", "<") ||
            $fact["source_reference"] !==
                "eprescription.medication-administration:" .
                    $fact["administration_id"]
        ) {
            throw new \InvalidArgumentException(
                "Unsupported administration publication version or source reference.",
            );
        }
    }

    private function assertScalarMap(array $values): void
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                $this->assertScalarMap($value);
            } elseif ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException(
                    "Administration publications carry scalar/array snapshots only.",
                );
            }
        }
    }

    private function canonical(array $values): array
    {
        if (!array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $values;
    }
}
