<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ViewActiveClinicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can("clinicaldocumentation.records.read") ===
            true;
    }

    public function rules(): array
    {
        return [];
    }
}
