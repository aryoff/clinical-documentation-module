<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\ClinicalDocumentation\Models\Concerns\RefilesUnderCanonicalPatient;

/**
 * A Clinical Record Legal Hold. Its own update path only ever lifts it, once; following an identity reconciliation goes
 * through `reassignPatient()`. Nothing else is editable and it is never removed.
 */
class LegalHold extends Model
{
    use HasUuids;
    use RefilesUnderCanonicalPatient;

    private const LIFT_COLUMNS = ['lifted_at', 'lifted_by', 'lifted_by_name', 'lift_reason', 'updated_at'];

    protected $table = 'cd_legal_holds';

    protected $guarded = [];

    protected $casts = ['record_refs' => 'array', 'placed_at' => 'datetime', 'lifted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (self $hold): void {
            if (array_diff(array_keys($hold->getDirty()), self::LIFT_COLUMNS) !== [] || $hold->getOriginal('lifted_at') !== null) {
                throw new \LogicException('A legal hold is only ever lifted once; it is never edited.');
            }
        });

        static::deleting(static function (): void {
            throw new \LogicException('A legal hold cannot be deleted; lift it.');
        });
    }
}
