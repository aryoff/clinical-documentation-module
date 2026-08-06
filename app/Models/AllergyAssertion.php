<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AllergyAssertion extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_allergy_assertions';

    protected $fillable = ['document_id', 'patient_id', 'substance', 'reaction', 'severity', 'verification_status', 'active', 'asserted_by', 'asserted_by_name', 'asserted_at'];

    protected $casts = ['active' => 'boolean', 'asserted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
        });

        static::updating(static function (): void {
            throw new \LogicException('Allergy Assertions are immutable; assert a new state instead.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('Allergy Assertions cannot be deleted.');
        });
    }
}
