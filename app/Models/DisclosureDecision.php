<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One authorization decision, granted or refused. Insert-only. */
class DisclosureDecision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'cd_disclosure_decisions';

    protected $guarded = [];

    protected $casts = ['current_version' => 'boolean', 'historical_copy' => 'boolean', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new \LogicException('Disclosure evidence is insert-only.'));
        static::deleting(static fn () => throw new \LogicException('Disclosure evidence cannot be deleted.'));
    }
}
