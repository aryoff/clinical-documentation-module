<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Revising the draft. See CreateClinicalDocumentRequest for why this gates here. */
class UpdateClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.documents.author') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'payload' => ['sometimes', 'array'],
            'encountered_at' => ['sometimes', 'date'],
        ];
    }
}
