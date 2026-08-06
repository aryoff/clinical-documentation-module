<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DiagnosisAssertion extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_diagnosis_assertions';

    protected $fillable = ['document_id', 'patient_id', 'coding_system', 'code', 'display', 'assertion_type', 'note', 'asserted_by', 'asserted_by_name', 'asserted_at'];

    protected $casts = ['asserted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(static function (): void {
            throw new \LogicException('Diagnosis Assertions are immutable; assert a new fact instead.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('Diagnosis Assertions cannot be deleted.');
        });
    }
}
