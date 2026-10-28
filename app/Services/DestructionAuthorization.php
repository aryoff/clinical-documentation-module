<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\DestructionAuthorizationContract;
use Modules\ClinicalDocumentation\Models\Destruction;

/** Provider of `clinicaldocumentation.destruction-authorization`: one step, one answer, never permission by default. */
final class DestructionAuthorization implements DestructionAuthorizationContract
{
    public function __construct(private readonly ClinicalRecordDestruction $destruction) {}

    public function permits(string $authorizationId, string $patientId, string $custodian, string $refType, string $refId): array
    {
        $destruction = Str::isUuid($authorizationId) ? Destruction::query()->find($authorizationId) : null;
        if ($destruction === null || $destruction->patient_id !== $patientId) {
            return ['permitted' => false, 'reason' => 'unknown_authorization'];
        }
        if (! $destruction->items()->where(['custodian' => $custodian, 'ref_type' => $refType, 'ref_id' => $refId])->exists()) {
            return ['permitted' => false, 'reason' => 'not_in_the_record_set'];
        }
        $problem = $this->destruction->problem($destruction);

        return $problem === null ? ['permitted' => true] : ['permitted' => false, 'reason' => $problem];
    }
}
