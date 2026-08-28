<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PresentedExternalEvidenceReview extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_presented_external_evidence_reviews';

    protected $fillable = ['evidence_id', 'document_id', 'patient_id', 'reviewed_by', 'reviewed_by_name', 'reviewed_at'];

    protected $casts = ['reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(static function (): void {
            throw new \LogicException('Presented external evidence reviews are immutable.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('Presented external evidence reviews cannot be deleted.');
        });
    }
}
