<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Models\AllergyAssertion;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Models\ClinicalHandoff;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Models\PatientReassignment;
use Modules\ClinicalDocumentation\Models\PresentedExternalEvidence;

/**
 * Inbox for `hospitalcore.patient-identity-reconciled` v1.
 *
 * When the registration desk identifies a patient who was treated under a
 * Provisional Patient record, the diagnosis the ER clinician asserted has to
 * follow them — otherwise the ward attending who accepts the handoff for the
 * canonical patient opens an empty panel, which is #288.
 *
 * The event is a named string carrying a scalar payload, so this module holds
 * no HospitalCore import and stays installable in a fork with no registry of
 * that name.
 *
 * **Which tables move is this context's decision, per ADR 0020.** Everything
 * named below is *current state*: what is true about this patient now, which a
 * clinician reads by patient. A point-in-time record of what was true when it
 * was written keeps the identity it was written under and is resolved forward
 * at read time instead — `cd_clinical_audit_events` and `cd_discharge_receipts`
 * are that shape, and are deliberately absent here.
 *
 * `cd_presented_external_evidence_reviews` and `cd_archive_packages` are also
 * absent, for a third reason: each is reached through a key that itself moves
 * (`evidence_id`, `document_id`), so reassigning them would be a second write
 * to say what the first already said.
 */
final class ReassignReconciledPatient
{
    /**
     * Current-state records this context refiles onto the canonical identity.
     *
     * Every one of these carries an immutability guard, so each moves through
     * its own `reassignPatient()` carve-out rather than a mass update. The
     * carve-out is the ADR 0020 convention: identity-filing is a different
     * write from clinical content, and naming it keeps that visible.
     *
     * @var array<string, class-string<Model>>
     */
    private const GUARDED_MODELS = [
        'cd_clinical_documents' => ClinicalDocument::class,
        'cd_diagnosis_assertions' => DiagnosisAssertion::class,
        'cd_allergy_assertions' => AllergyAssertion::class,
        'cd_diagnostic_result_evidence' => DiagnosticResultEvidence::class,
        'cd_presented_external_evidence' => PresentedExternalEvidence::class,
    ];

    /**
     * Current-state records with no immutability guard.
     *
     * A ward handoff is a live piece of workflow — it says who owes the
     * patient's care right now — so a plain mass update is the whole job.
     *
     * @var array<string, class-string<Model>>
     */
    private const UNGUARDED_MODELS = [
        'cd_clinical_handoffs' => ClinicalHandoff::class,
    ];

    /** @param array<string, mixed> $fact */
    public function handle(array $fact): void
    {
        $canonicalId = (string) $fact['canonical_patient_id'];
        $supersededId = (string) $fact['superseded_patient_id'];

        DB::transaction(function () use ($fact, $canonicalId, $supersededId): void {
            $reassigned = [];

            foreach (self::GUARDED_MODELS as $table => $model) {
                $moved = 0;
                foreach ($model::where('patient_id', $supersededId)->cursor() as $record) {
                    $record->reassignPatient($canonicalId);
                    $moved++;
                }
                $reassigned[$table] = $moved;
            }

            foreach (self::UNGUARDED_MODELS as $table => $model) {
                $reassigned[$table] = $model::where('patient_id', $supersededId)
                    ->update(['patient_id' => $canonicalId]);
            }

            // Recorded even when every count is zero. "This context saw the
            // reconciliation and had nothing to move" and "this context never
            // heard about it" are different answers to the same question, and
            // only the record tells them apart.
            PatientReassignment::create([
                'canonical_patient_id' => $canonicalId,
                'superseded_patient_id' => $supersededId,
                'reassigned' => $reassigned,
                'reconciled_by' => $fact['reconciled_by'],
                'reason' => $fact['reason'],
                'reconciled_at' => $fact['reconciled_at'],
            ]);
        });
    }
}
