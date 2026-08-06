<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ClinicalAddendum extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_clinical_addenda';

    protected $fillable = ['document_id', 'author_id', 'author_name', 'reason', 'payload', 'encountered_at', 'signed_at', 'signed_by', 'signed_by_name'];

    protected $casts = ['payload' => 'array', 'encountered_at' => 'datetime', 'signed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(function (self $model): void {
            if ($model->getOriginal('signed_at') !== null) {
                throw new \LogicException('Signed clinical addenda are immutable.');
            }
        });

        static::deleting(function (self $model): void {
            if ($model->signed_at !== null) {
                throw new \LogicException('Signed clinical addenda cannot be deleted.');
            }
        });
    }
}
