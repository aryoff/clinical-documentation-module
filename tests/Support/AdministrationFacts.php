<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Support;

use Illuminate\Support\Str;

trait AdministrationFacts
{
    protected function requireAdministrationPublisher(): void
    {
        if (
            !app(\App\Support\CapabilityRegistry::class)->isResolved(
                "eprescription.administration-clinical-fact",
            )
        ) {
            $this->markTestSkipped(
                "Administration source stream is not composed; ClinicalDocumentation remains available.",
            );
        }
    }

    /** A scalar publication fixture; no dependency on the source module's classes. */
    protected function administrationFact(array $overrides = []): array
    {
        $administration_id = (string) Str::uuid();

        return array_replace(
            [
                "fact_id" => (string) Str::uuid(),
                "fact_version" => "1.4.0",
                "type" => "recorded",
                "occurred_at" => "2026-10-12T10:02:00+07:00",
                "source_reference" =>
                    "eprescription.medication-administration:" .
                    $administration_id,
                "administration_id" => $administration_id,
                "corrects_administration_id" => null,
                "correction_reason" => null,
                "registration_id" => "f3a5e0ad-4f0d-4795-8e5f-d62441b2a513",
                "patient_id" => "e759fc76-5732-4fe1-a14d-a820cda7bbcb",
                "outcome" => "given",
                "reason" => null,
                "prn_indication" => "Pain",
                "missed_policy" => null,
                "medicine" => [
                    "product_id" => (string) Str::uuid(),
                    "product_code" => "PCM500",
                    "product_name" => "Paracetamol",
                    "active_ingredients" => ["paracetamol"],
                ],
                "dose" => ["quantity" => "500", "unit" => "mg"],
                "route" => "oral",
                "administered_at" => "2026-10-12T10:00:00+07:00",
                "recorded_at" => "2026-10-12T10:02:00+07:00",
                "administered_by" => [
                    "id" => (string) Str::uuid(),
                    "name" => "Nurse Ari",
                ],
                "recorded_by" => [
                    "id" => (string) Str::uuid(),
                    "name" => "Recorder Dewi",
                ],
                "authority" => ["profession" => "nurse"],
                "verification" => ["patient" => true, "medicine" => true],
                "double_check" => null,
                "safety_policy_version" => null,
                "signature" => ["attested" => true],
                "care_location" => [
                    "id" => (string) Str::uuid(),
                    "code" => "WARD-A",
                    "name" => "Ward A",
                ],
                "basis" => [
                    "type" => "none",
                    "state" => "unreconciled",
                    "prescription_id" => null,
                    "prescription_line_id" => null,
                    "due_slot_at" => null,
                    "order_snapshot" => null,
                    "reconciliation_id" => null,
                    "protocol_version_id" => null,
                    "protocol_application_id" => null,
                    "protocol_snapshot" => null,
                ],
                "variable_dose_selection" => null,
                "variable_dose_deviation" => null,
            ],
            $overrides,
        );
    }
}
