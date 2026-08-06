<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClinicalDocument extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_clinical_documents';

    protected $fillable = ['handoff_id', 'registration_id', 'patient_id', 'template', 'template_version', 'status', 'author_id', 'author_name', 'payload', 'encountered_at', 'signed_at', 'signed_by', 'signed_by_name'];

    protected $casts = ['payload' => 'array', 'encountered_at' => 'datetime', 'signed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(function (self $model): void {
            if ($model->getOriginal('status') === 'signed') {
                throw new \LogicException('Signed clinical documents are immutable; create an addendum instead.');
            }
        });

        static::deleting(function (self $model): void {
            if ($model->status === 'signed') {
                throw new \LogicException('Signed clinical documents cannot be deleted.');
            }
        });
    }
}
