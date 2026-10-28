<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One custodian copy a destruction covers, tracked to its receipt. Never removed. */
class DestructionItem extends Model
{
    use HasUuids;

    protected $table = 'cd_destruction_items';

    protected $guarded = [];

    protected $casts = ['destroyed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (self $item): void {
            if (array_intersect(array_keys($item->getDirty()), ['destruction_id', 'custodian', 'ref_type', 'ref_id']) !== []) {
                throw new \LogicException('The copies a destruction covers are never edited.');
            }
        });

        static::deleting(static function (): void {
            throw new \LogicException('A destruction item cannot be deleted.');
        });
    }
}
