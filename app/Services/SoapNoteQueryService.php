<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Modules\ClinicalDocumentation\DTOs\VitalsData;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;

/**
 * @deprecated MedicalRecords #56 must consume clinicaldocumentation.active-clinical-record instead.
 *
 * This adapter prevents an installed legacy MedicalRecords package from failing
 * to boot while that migration is delivered. It exposes no authoring or record
 * access and reads only the optional vitals projection from signed documents.
 */
class SoapNoteQueryService
{
    public function getLatestVitalsForPatient(string $patientId): ?VitalsData
    {
        $document = ClinicalDocument::query()
            ->where('patient_id', $patientId)
            ->where('status', 'signed')
            ->latest('signed_at')
            ->get()
            ->first(static fn (ClinicalDocument $document): bool => is_array($document->payload['vitals'] ?? null));

        return $document === null ? null : new VitalsData($document->payload['vitals']);
    }
}
