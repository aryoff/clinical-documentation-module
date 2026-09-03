<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DiagnosticResultEvidence extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_diagnostic_result_evidence';

    protected $fillable = ['patient_id', 'registration_id', 'source_owner', 'result_reference_id', 'coding_system', 'code', 'display', 'summary', 'observed_at', 'released_by', 'released_by_name', 'recorded_at'];

    protected $casts = ['observed_at' => 'datetime', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(static function (): void {
            throw new \LogicException('Diagnostic Result Evidence is immutable; publish a corrected result instead.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('Diagnostic Result Evidence cannot be deleted.');
        });
    }
}
