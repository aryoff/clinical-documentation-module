<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClinicalArchivePackage extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_archive_packages';

    protected $fillable = ['document_id', 'patient_id', 'custody_state', 'integrity_hash', 'requested_at', 'custody_acknowledged_at'];

    protected $casts = ['requested_at' => 'datetime', 'custody_acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }
}
