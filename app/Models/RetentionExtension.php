<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A continuing-usefulness decision. Insert-only apart from following an identity reconciliation. */
class RetentionExtension extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'cd_retention_extensions';

    protected $guarded = [];

    protected $casts = ['retain_until' => 'date', 'created_at' => 'datetime'];

    /** Identity filing is a different write from the decision itself (ADR 0020), so it has its own carve-out. */
    public function reassignPatient(string $canonicalPatientId): void
    {
        $this->patient_id = $canonicalPatientId;
        $this->allowIdentityFiling = true;
        $this->save();
    }

    private bool $allowIdentityFiling = false;

    protected static function booted(): void
    {
        static::updating(static function (self $extension): void {
            if (! $extension->allowIdentityFiling || array_keys($extension->getDirty()) !== ['patient_id']) {
                throw new \LogicException('A retention extension is immutable.');
            }
        });

        static::deleting(static function (): void {
            throw new \LogicException('A retention extension cannot be deleted.');
        });
    }
}
