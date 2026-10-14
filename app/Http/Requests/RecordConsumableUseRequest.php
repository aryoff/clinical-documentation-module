<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ClinicalDocumentation\Services\ClinicalConsumableUsageService;

/** Recording an actual use moves stock; the action it gates is the use, not the order. */
class RecordConsumableUseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // The custodian's own permission too: a user who cannot complete the stock effect is not offered the route.
        return $user?->can(ClinicalConsumableUsageService::RECORD_PERMISSION) === true &&
            $user->can(ClinicalConsumableUsageService::CUSTODIAN_PERMISSION);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            "idempotency_key" => ["required", "uuid"],
            "registration_id" => ["required", "uuid"],
            "order_id" => ["nullable", "uuid"],
            "profile_id" => ["required", "uuid"],
            "product_id" => ["required", "uuid"],
            "batch_number" => ["required", "string", "max:100"],
            "expiry_date" => ["required", "date_format:Y-m-d"],
            "quantity" => ["required", "numeric", "min:0.001", "max:9999999.999", "decimal:0,3"],
        ];
    }
}
