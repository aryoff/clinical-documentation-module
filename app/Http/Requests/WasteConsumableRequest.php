<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ClinicalDocumentation\Services\ClinicalConsumableUsageService;

/** Waste of a recorded use; it removes stock and never returns it. */
class WasteConsumableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->can(ClinicalConsumableUsageService::RECORD_PERMISSION) === true &&
            $user->can(ClinicalConsumableUsageService::CUSTODIAN_PERMISSION);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            "idempotency_key" => ["required", "uuid"],
            "quantity" => ["required", "numeric", "min:0.001", "max:9999999.999", "decimal:0,3"],
            "reason" => ["required", "in:opened,contaminated,damaged,lost"],
        ];
    }
}
