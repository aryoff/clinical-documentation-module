<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Support;

use Illuminate\Support\Facades\DB;

/**
 * Serializes writes that change a patient's retention protection (holds, extensions) with the destruction step that
 * relies on it. A transaction-scoped advisory lock: taken inside a transaction, released when it ends.
 *
 * Visit-history writes belong to HospitalCore and cannot take this lock; the per-step standing check bounds that window.
 */
final class ProtectionLock
{
    public static function forPatient(string $patientId): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['cd-protection:'.$patientId]);
        }
    }
}
