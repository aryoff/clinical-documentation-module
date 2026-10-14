<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Support;

use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;

/** One snapshot shape for original publication, republication and clinical reads. */
final class DiagnosisAssertionSnapshot
{
    /** @return array<string, mixed> */
    public static function from(DiagnosisAssertion $assertion): array
    {
        return [
            'assertion_id' => $assertion->id,
            'lineage_id' => $assertion->lineage_id,
            'revision' => $assertion->revision,
            'document_id' => $assertion->document_id,
            'registration_id' => $assertion->registration_id,
            'patient_id' => $assertion->patient_id,
            'coding_system' => $assertion->coding_system,
            'code' => $assertion->code,
            'display' => $assertion->display,
            'assertion_type' => $assertion->assertion_type,
            'note' => $assertion->note,
            // The credential the asserting clinician held at the moment they
            // asserted, where one was frozen. A reader auditing an admission
            // needs it on the fact rather than by joining back to the module
            // that took the copy.
            'clinical_authority_snapshot' => $assertion->clinical_authority_snapshot,
            'asserted_by' => $assertion->asserted_by,
            'asserted_by_name' => $assertion->asserted_by_name,
            'asserted_at' => $assertion->asserted_at->toAtomString(),
        ];
    }
}
