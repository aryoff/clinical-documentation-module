<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The record that a discharge summary was explained to somebody.
 *
 * Insert-only, guarded here rather than trusted: a correction is a new receipt,
 * because "who was told what, and when" is the question a complaint turns on
 * and an edited answer to it is no answer.
 */
class DischargeReceipt extends Model
{
    public const TO_PATIENT = 'patient';

    public const TO_REPRESENTATIVE = 'representative';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_discharge_receipts';

    protected $fillable = [
        'document_id',
        'registration_id',
        'patient_id',
        'recipient_kind',
        'recipient_name',
        'recipient_relationship',
        'language',
        'interpreter_name',
        'accessibility_support',
        'explanation_summary',
        'explained_at',
        'explained_by',
        'explained_by_name',
    ];

    protected $casts = ['explained_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(function (): void {
            throw new \LogicException('A discharge receipt is insert-only; record a new one instead.');
        });

        static::deleting(function (): void {
            throw new \LogicException('A discharge receipt cannot be deleted.');
        });
    }
}
