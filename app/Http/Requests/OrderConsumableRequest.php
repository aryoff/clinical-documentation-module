<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ClinicalDocumentation\Services\ClinicalConsumableUsageService;

/** A clinical instruction with no stock or billing effect until an actual use is recorded. */
class OrderConsumableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(ClinicalConsumableUsageService::ORDER_PERMISSION) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            "idempotency_key" => ["required", "uuid"],
            "registration_id" => ["required", "uuid"],
            "product_id" => ["required", "uuid"],
            "quantity" => ["required", "numeric", "min:0.001", "max:9999999.999", "decimal:0,3"],
            "note" => ["nullable", "string", "max:1000"],
        ];
    }
}
