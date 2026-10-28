<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One destruction of a patient's electronic findings. Its who, why and when are never edited, and it is never removed. */
class Destruction extends Model
{
    use HasUuids;

    protected $table = 'cd_destructions';

    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime', 'dependencies_acknowledged' => 'boolean'];

    public function items(): HasMany
    {
        return $this->hasMany(DestructionItem::class, 'destruction_id')->orderBy('custodian')->orderBy('ref_type')->orderBy('ref_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (self $destruction): void {
            if (array_intersect(array_keys($destruction->getDirty()), ['patient_id', 'reason', 'destroyed_by', 'started_at']) !== []) {
                throw new \LogicException('A destruction record is never edited.');
            }
        });

        static::deleting(static function (): void {
            throw new \LogicException('A destruction record cannot be deleted.');
        });
    }
}
