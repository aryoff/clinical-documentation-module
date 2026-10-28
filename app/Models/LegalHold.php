<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Clinical Record Legal Hold. It changes exactly twice in its life at most: it is lifted, and it follows an
 * identity reconciliation. Nothing else is editable and it is never removed.
 */
class LegalHold extends Model
{
    use HasUuids;

    private const LIFT_COLUMNS = ['lifted_at', 'lifted_by', 'lifted_by_name', 'lift_reason', 'updated_at'];

    protected $table = 'cd_legal_holds';

    protected $guarded = [];

    protected $casts = ['record_refs' => 'array', 'placed_at' => 'datetime', 'lifted_at' => 'datetime'];

    private bool $allowIdentityFiling = false;

    public function reassignPatient(string $canonicalPatientId): void
    {
        $this->patient_id = $canonicalPatientId;
        $this->allowIdentityFiling = true;
        $this->save();
    }

    protected static function booted(): void
    {
        static::updating(static function (self $hold): void {
            $changed = array_keys($hold->getDirty());
            $allowed = $hold->allowIdentityFiling ? ['patient_id', 'updated_at'] : self::LIFT_COLUMNS;
            if (array_diff($changed, $allowed) !== [] || ($hold->getOriginal('lifted_at') !== null && ! $hold->allowIdentityFiling)) {
                throw new \LogicException('A legal hold is only ever lifted once; it is never edited.');
            }
        });

        static::deleting(static function (): void {
            throw new \LogicException('A legal hold cannot be deleted; lift it.');
        });
    }
}
