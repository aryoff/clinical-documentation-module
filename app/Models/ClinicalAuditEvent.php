<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClinicalAuditEvent extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_clinical_audit_events';

    protected $fillable = ['patient_id', 'document_id', 'addendum_id', 'subject_type', 'subject_id', 'action', 'actor_id', 'causer_id', 'actor_name', 'reason', 'correlation_id', 'metadata', 'occurred_at'];

    protected $casts = ['metadata' => 'array', 'occurred_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(static function (): void {
            throw new \LogicException('Clinical audit evidence is insert-only.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('Clinical audit evidence cannot be deleted.');
        });
    }
}
