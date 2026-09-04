<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models\Concerns;

/**
 * The one sanctioned exception to a clinical record's immutability guard.
 *
 * Every model using this refuses ordinary `update()` and `delete()` — a
 * clinical fact is never rewritten; a new one is asserted instead. That guard
 * protects clinical *content*: what was asserted, when, and by whom.
 *
 * Whose row it is filed under is a different kind of write. When the Patient
 * Identity Steward reconciles a Provisional Patient into the canonical record
 * they turned out to be, a record that stays behind is invisible to everyone
 * treating that patient — the ward attending opens an empty diagnosis panel
 * (#288). So the record follows the patient, and ADR 0020 requires the bypass
 * be a named method rather than a query-builder update, so that a reader
 * auditing the immutability rule finds the exception where they look for it.
 *
 * Nothing here is shared outside this module: ADR 0020 rejects a cross-module
 * reassignment mechanism, and each context decides for itself which of its own
 * tables are current state.
 */
trait RefilesUnderCanonicalPatient
{
    public function reassignPatient(string $canonicalPatientId): void
    {
        static::withoutEvents(function () use ($canonicalPatientId): void {
            $this->forceFill(['patient_id' => $canonicalPatientId])->save();
        });
    }
}
