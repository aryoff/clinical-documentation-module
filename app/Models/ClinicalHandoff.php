<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClinicalHandoff extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_clinical_handoffs';

    protected $fillable = ['registration_id', 'patient_id', 'source_owner', 'source_reference_id', 'recipient_id', 'accepted_by', 'accepted_by_name', 'accepted_at'];

    protected $casts = ['accepted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });
    }
}
