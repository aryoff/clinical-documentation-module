<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The permanent history of a hold: who placed or lifted it, why, and when. Insert-only. */
class LegalHoldEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'cd_legal_hold_events';

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('A hold event is insert-only.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('A hold event cannot be deleted.');
        });
    }
}
