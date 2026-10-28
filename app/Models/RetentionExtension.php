<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\ClinicalDocumentation\Models\Concerns\RefilesUnderCanonicalPatient;

/** A continuing-usefulness decision. Insert-only apart from following an identity reconciliation. */
class RetentionExtension extends Model
{
    use HasUuids;
    use RefilesUnderCanonicalPatient;

    public $timestamps = false;

    protected $table = 'cd_retention_extensions';

    protected $guarded = [];

    protected $casts = ['retain_until' => 'date', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('A retention extension is immutable.');
        });

        static::deleting(static function (): void {
            throw new \LogicException('A retention extension cannot be deleted.');
        });
    }
}
