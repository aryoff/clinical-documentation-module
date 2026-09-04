<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * This context's record of a patient-identity reconciliation it acted on.
 *
 * The mirror of `HospitalCore\Models\PatientIdentityReconciliation`, kept here
 * rather than shared: ADR 0020 rejects a cross-module reassignment mechanism,
 * and only this context knows which of its tables are current state.
 */
class PatientReassignment extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_patient_reassignments';

    protected $fillable = [
        'canonical_patient_id', 'superseded_patient_id',
        'reassigned', 'reconciled_by', 'reason', 'reconciled_at',
    ];

    protected $casts = ['reassigned' => 'array', 'reconciled_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(static function (): void {
            throw new \LogicException('A reassignment record is insert-only.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('A reassignment record cannot be deleted.');
        });
    }
}
