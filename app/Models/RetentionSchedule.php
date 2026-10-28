<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A recorded retention schedule version. Insert-only: a later version supersedes it, nothing edits it. */
class RetentionSchedule extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'cd_retention_schedules';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('A retention schedule version is immutable; record a new version instead.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('A retention schedule version cannot be deleted.');
        });
    }
}
