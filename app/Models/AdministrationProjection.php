<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;

class AdministrationProjection extends Model
{
    public $timestamps = false;
    protected $keyType = "string";
    public $incrementing = false;
    protected $table = "cd_administration_projections";
    protected $fillable = [
        "id",
        "patient_id",
        "registration_id",
        "source_reference",
        "type",
        "fact",
        "occurred_at",
    ];
    protected $casts = [
        "fact" => "array",
        "occurred_at" => "immutable_datetime",
        "received_at" => "immutable_datetime",
    ];
    protected function getJsonCastFlags($key): int
    {
        return parent::getJsonCastFlags($key) | JSON_PRESERVE_ZERO_FRACTION;
    }
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException(
                "Administration projections are immutable; source corrections append new evidence.",
            );
        });
        static::deleting(static function (): void {
            throw new \LogicException(
                "Administration projections cannot be deleted.",
            );
        });
    }
}
