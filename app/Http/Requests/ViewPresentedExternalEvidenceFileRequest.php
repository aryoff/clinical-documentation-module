<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ClinicalDocumentation\Services\PresentedExternalEvidenceService;

class ViewPresentedExternalEvidenceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny([
            PresentedExternalEvidenceService::STAGING_PERMISSION,
            'clinicaldocumentation.documents.author',
        ]) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
