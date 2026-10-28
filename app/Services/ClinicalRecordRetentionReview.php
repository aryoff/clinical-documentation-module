<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Carbon\CarbonImmutable;
use Modules\ClinicalDocumentation\Contracts\PatientLastVisitPort;
use Modules\ClinicalDocumentation\Models\LegalHold;
use Modules\ClinicalDocumentation\Models\RetentionExtension;

/**
 * Whether a patient's electronic records may even be considered for destruction. Reporting only: reaching a retention
 * date permits Laboratory Record Destruction Review, never deletion, and nothing here removes anything.
 *
 * Eligibility is the absence of every blocker. Missing or uncertain evidence is a blocker, never a pass.
 */
final class ClinicalRecordRetentionReview
{
    public function __construct(
        private readonly ClinicalRecordRetention $retention,
        private readonly PatientLastVisitPort $visits,
        private readonly DestructionEnablement $enablement,
    ) {}

    /** @return array<string, mixed> */
    public function assess(string $patientId): array
    {
        $now = CarbonImmutable::now();
        $schedule = $this->retention->currentSchedule();
        $blockers = [];

        if ($schedule === null) {
            $blockers[] = 'no_retention_schedule';
        }

        // Coverage is judged up to a little before now: no provider can vouch for the very last moment.
        $cutoff = $now->subMinutes((int) config('clinicaldocumentation.retention.coverage_tolerance_minutes', 60));
        try {
            $visit = $this->visits->lastVisit($patientId, $cutoff->toIso8601String());
        } catch (\InvalidArgumentException) {
            $visit = null;
            $blockers[] = 'patient_not_found';
        }
        if ($visit === null && ! in_array('patient_not_found', $blockers, true)) {
            $blockers[] = 'last_visit_unavailable';
        }

        $lastVisit = $visit['last_visit_ended_at'] ?? null;
        array_push($blockers, ...($visit['coverage']['blockers'] ?? []));
        // The review does not rely on the provider having listed every reason: it re-derives the three that must
        // never pass, so a terse or older provider cannot turn uncertainty into eligibility.
        if ($visit !== null) {
            if (($visit['ongoing'] ?? false) === true) {
                $blockers[] = 'ongoing_attendance';
            }
            if ($lastVisit === null) {
                $blockers[] = 'no_attendance_evidence';
            }
            if (($visit['coverage']['complete'] ?? false) !== true && ($visit['coverage']['blockers'] ?? []) === []) {
                $blockers[] = 'attendance_evidence_incomplete';
            }
        }

        $protectedUntil = $lastVisit !== null && $schedule !== null
            ? CarbonImmutable::parse($lastVisit)->addYears($schedule->minimum_years)
            : null;
        if ($protectedUntil !== null && $protectedUntil->greaterThan($now)) {
            $blockers[] = 'retention_not_expired';
        }

        $extensionUntil = RetentionExtension::query()->where('patient_id', $patientId)->max('retain_until');
        if ($extensionUntil !== null && CarbonImmutable::parse($extensionUntil)->endOfDay()->greaterThan($now)) {
            $blockers[] = 'continuing_usefulness_extension';
        }

        $holds = LegalHold::query()->where('patient_id', $patientId)->whereNull('lifted_at')->orderBy('placed_at')->get();
        if ($holds->isNotEmpty()) {
            $blockers[] = 'active_legal_hold';
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'patient_id' => $patientId,
            'schedule_version' => $schedule?->version,
            'minimum_years' => $schedule?->minimum_years,
            'last_visit_ended_at' => $lastVisit,
            'history_version' => $visit['history_version'] ?? null,
            // The coverage the last visit was judged against, so a date is never shown without the cutoff behind it.
            'coverage_through' => $visit['coverage']['through'] ?? $cutoff->toIso8601String(),
            'coverage_complete' => ($visit['coverage']['complete'] ?? false) === true,
            'protected_until' => $protectedUntil?->toIso8601String(),
            'extension_until' => $extensionUntil === null ? null : CarbonImmutable::parse($extensionUntil)->toDateString(),
            'holds' => $holds->map(fn (LegalHold $hold): array => [
                'id' => $hold->id,
                'scope' => $hold->scope,
                'record_refs' => $hold->record_refs,
                'reason' => $hold->reason,
                'placed_by_name' => $hold->placed_by_name,
                'placed_at' => $hold->placed_at->toIso8601String(),
            ])->all(),
            'blockers' => $blockers,
            'eligible_for_review' => $blockers === [],
            // Whether execution is available at all, from the deployment's conditions; the review itself destroys nothing.
            'destruction_enabled' => $this->enablement->enabled(),
        ];
    }
}
