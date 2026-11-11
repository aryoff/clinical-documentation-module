<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

class ReviewPresentedExternalEvidenceRequest extends ClinicalFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.documents.author') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['document_id' => ['required', 'uuid']];
    }
}
