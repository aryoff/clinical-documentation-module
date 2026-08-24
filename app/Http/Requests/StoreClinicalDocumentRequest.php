<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Creating the draft. See CreateClinicalDocumentRequest for why this gates here. */
class StoreClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.documents.author') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'handoff_id' => ['required', 'uuid'],
            'template' => ['required', 'string'],
            'template_version' => ['required', 'string'],
            'encountered_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ];
    }
}
