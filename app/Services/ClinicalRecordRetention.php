<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Models\LegalHold;
use Modules\ClinicalDocumentation\Models\LegalHoldEvent;
use Modules\ClinicalDocumentation\Models\RetentionExtension;
use Modules\ClinicalDocumentation\Models\RetentionSchedule;

/**
 * Clinical Record Retention policy and the Legal Hold register: versioned schedules, continuing-usefulness extensions
 * and holds. It records decisions and enforces nothing by itself; `ClinicalRecordRetentionReview` reads them.
 */
final class ClinicalRecordRetention
{
    /** The statutory minimum, in years, a schedule may never go below. */
    public const FLOOR_YEARS = 25;

    public function recordSchedule(int $minimumYears, string $rationale, string $actorId): RetentionSchedule
    {
        if ($minimumYears < self::FLOOR_YEARS) {
            throw ValidationException::withMessages(['minimum_years' => __('clinicaldocumentation::messages.retention_below_floor', ['years' => self::FLOOR_YEARS])]);
        }

        return DB::transaction(function () use ($minimumYears, $rationale, $actorId): RetentionSchedule {
            $latest = RetentionSchedule::query()->orderByDesc('version')->lockForUpdate()->first();
            // A policy change can lengthen protection but never silently shorten what existing records already have.
            if ($latest !== null && $minimumYears < $latest->minimum_years) {
                throw ValidationException::withMessages(['minimum_years' => __('clinicaldocumentation::messages.retention_cannot_shorten', ['years' => $latest->minimum_years])]);
            }

            return RetentionSchedule::create([
                'version' => ($latest?->version ?? 0) + 1,
                'minimum_years' => $minimumYears,
                'rationale' => $rationale,
                'created_by' => $actorId,
                'created_by_name' => $this->name($actorId),
            ]);
        });
    }

    public function currentSchedule(): ?RetentionSchedule
    {
        return RetentionSchedule::query()->orderByDesc('version')->first();
    }

    public function recordExtension(string $patientId, string $retainUntil, string $reason, string $actorId): RetentionExtension
    {
        return RetentionExtension::create([
            'patient_id' => $patientId,
            'retain_until' => $retainUntil,
            'reason' => $reason,
            'decided_by' => $actorId,
            'decided_by_name' => $this->name($actorId),
        ]);
    }

    /** @param list<array{type: string, id: string}>|null $recordRefs */
    public function placeHold(string $patientId, string $scope, ?array $recordRefs, string $reason, string $actorId): LegalHold
    {
        return DB::transaction(function () use ($patientId, $scope, $recordRefs, $reason, $actorId): LegalHold {
            $hold = LegalHold::create([
                'patient_id' => $patientId,
                'scope' => $scope,
                'record_refs' => $scope === 'record_set' ? array_values($recordRefs ?? []) : null,
                'reason' => $reason,
                'placed_by' => $actorId,
                'placed_by_name' => $this->name($actorId),
                'placed_at' => CarbonImmutable::now(),
            ]);
            $this->event($hold, 'placed', $reason, $actorId);

            return $hold;
        });
    }

    public function liftHold(string $holdId, string $reason, string $actorId): LegalHold
    {
        return DB::transaction(function () use ($holdId, $reason, $actorId): LegalHold {
            $hold = LegalHold::query()->lockForUpdate()->findOrFail($holdId);
            if ($hold->lifted_at !== null) {
                throw ValidationException::withMessages(['hold' => __('clinicaldocumentation::messages.retention_hold_already_lifted')]);
            }
            $hold->update([
                'lifted_at' => CarbonImmutable::now(),
                'lifted_by' => $actorId,
                'lifted_by_name' => $this->name($actorId),
                'lift_reason' => $reason,
            ]);
            $this->event($hold, 'lifted', $reason, $actorId);

            return $hold;
        });
    }

    private function event(LegalHold $hold, string $action, string $reason, string $actorId): void
    {
        LegalHoldEvent::create([
            'hold_id' => $hold->id,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_name' => $this->name($actorId),
            'reason' => $reason,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function name(string $actorId): ?string
    {
        return User::query()->whereKey($actorId)->value('name');
    }
}
