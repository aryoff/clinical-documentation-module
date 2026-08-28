<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Custody record for a patient-provided external file.
 *
 * Clinical meaning, trust, and asserted source authority are deliberately not
 * columns here; those belong to the clinician's authoring act.
 */
class PresentedExternalEvidence extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'cd_presented_external_evidence';

    protected $fillable = [
        'registration_id',
        'patient_id',
        'file_vault_id',
        'claim',
        'staged_by',
        'staged_by_name',
        'staged_at',
    ];

    protected $casts = ['staged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(function (): void {
            throw new \LogicException('Presented external evidence custody facts are immutable.');
        });

        static::deleting(function (): void {
            throw new \LogicException('Presented external evidence cannot be deleted from the audit trail.');
        });
    }

    /** @return HasMany<PresentedExternalEvidenceReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(PresentedExternalEvidenceReview::class, 'evidence_id');
    }
}
