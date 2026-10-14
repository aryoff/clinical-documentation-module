<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of one Clinical Consumable Order or Usage; a correction is a new row. */
final class ClinicalConsumableEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;
    protected $table = "cd_consumable_events";
    protected $guarded = [];
    protected $casts = ["payload" => "array", "created_at" => "immutable_datetime"];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException(
                "Consumable usage events are immutable; a correction appends a new event.",
            );
        });
        static::deleting(static function (): void {
            throw new \LogicException("Consumable usage events cannot be deleted.");
        });
    }
}
