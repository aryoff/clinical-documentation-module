<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A Clinical Disclosure Request. Insert-only. */
class DisclosureRequest extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'cd_disclosure_requests';

    protected $guarded = [];

    protected $casts = ['scope_refs' => 'array', 'historical_copy_refs' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new \LogicException('Disclosure evidence is insert-only.'));
        static::deleting(static fn () => throw new \LogicException('Disclosure evidence cannot be deleted.'));
    }
}
