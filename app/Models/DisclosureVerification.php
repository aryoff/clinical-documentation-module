<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Recipient verification evidence. Insert-only. */
class DisclosureVerification extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'cd_disclosure_verifications';

    protected $guarded = [];

    protected $casts = ['verified_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static fn () => throw new \LogicException('Disclosure evidence is insert-only.'));
        static::deleting(static fn () => throw new \LogicException('Disclosure evidence cannot be deleted.'));
    }
}
