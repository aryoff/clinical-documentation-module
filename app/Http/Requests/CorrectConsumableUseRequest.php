<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ClinicalDocumentation\Services\ClinicalConsumableUsageService;

/** Approval is the custodian's decision; here only the person who names the correction is asked. */
class CorrectConsumableUseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->can(ClinicalConsumableUsageService::CORRECT_PERMISSION) === true &&
            $user->can(ClinicalConsumableUsageService::CUSTODIAN_PERMISSION);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            "idempotency_key" => ["required", "uuid"],
            // The distinct-approver rule is enforced below: the service compares the lowercased ids and
            // the custodian compares the stored users, so no spelling of one id passes as a second person.
            "approver_id" => ["required", "uuid"],
            "reason" => ["required", "string", "max:1000"],
        ];
    }
}
