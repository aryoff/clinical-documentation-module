<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;

class DiagnosisAssertion extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $table = 'cd_diagnosis_assertions';

    protected $fillable = ['lineage_id', 'document_id', 'registration_id', 'patient_id', 'coding_system', 'code', 'display', 'assertion_type', 'revision', 'supersedes_assertion_id', 'evidence_refs', 'note', 'clinical_authority_snapshot', 'asserted_by', 'asserted_by_name', 'asserted_at'];

    protected $casts = ['asserted_at' => 'datetime', 'revision' => 'integer', 'evidence_refs' => 'array', 'clinical_authority_snapshot' => 'array'];

    /**
     * A head is an assertion nothing has superseded. Derived rather than stored
     * so the append-only history is the single source of truth.
     */
    public function scopeCurrentHeads(Builder $query): Builder
    {
        return $query->whereNotExists(function (QueryBuilder $successor): void {
            $successor->selectRaw('1')
                ->from('cd_diagnosis_assertions as successors')
                ->whereColumn('successors.supersedes_assertion_id', 'cd_diagnosis_assertions.id');
        });
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->id ??= (string) Str::uuid();
            // An `initial` or `supplement` opens its own lineage, so the head
            // and the lineage are the same fact on the first revision.
            $model->lineage_id ??= $model->id;
        });

        static::updating(static function (): void {
            throw new \LogicException('Diagnosis Assertions are immutable; assert a new fact instead.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('Diagnosis Assertions cannot be deleted.');
        });
    }
}
