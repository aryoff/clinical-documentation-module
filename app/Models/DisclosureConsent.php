<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Clinical Disclosure Consent evidence. Its own update path only ever withdraws it, once; it is never removed. */
class DisclosureConsent extends Model
{
    use HasUuids;

    private const WITHDRAWAL_COLUMNS = ['withdrawn_at', 'withdrawn_by', 'withdrawn_by_name', 'withdrawal_reason', 'updated_at'];

    protected $table = 'cd_disclosure_consents';

    protected $guarded = [];

    protected $casts = ['scope_refs' => 'array', 'valid_from' => 'date', 'valid_until' => 'date', 'withdrawn_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (self $consent): void {
            if (array_diff(array_keys($consent->getDirty()), self::WITHDRAWAL_COLUMNS) !== [] || $consent->getOriginal('withdrawn_at') !== null) {
                throw new \LogicException('A disclosure consent is only ever withdrawn once; it is never edited.');
            }
        });
        static::deleting(static fn () => throw new \LogicException('A disclosure consent cannot be deleted; withdraw it.'));
    }
}
