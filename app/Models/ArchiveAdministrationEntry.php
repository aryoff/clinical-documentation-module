<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ArchiveAdministrationEntry extends Model
{
    public $timestamps = false;
    protected $keyType = "string";
    public $incrementing = false;
    protected $table = "cd_archive_administration_entries";
    protected $fillable = ["package_id", "projection_id"];

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
        static::updating(static function (): void {
            throw new \LogicException(
                "Archive administration references are append-only.",
            );
        });
        static::deleting(static function (): void {
            throw new \LogicException(
                "Archive administration references cannot be deleted.",
            );
        });
    }
}
