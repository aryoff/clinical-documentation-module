<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ClinicalDocumentation\Services\PresentedExternalEvidenceService;

class StagePresentedExternalEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(PresentedExternalEvidenceService::STAGING_PERMISSION) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'registration_id' => ['required', 'uuid'],
            'claim' => ['required', 'string', 'max:2000'],
            'file' => [
                'required',
                'file',
                'max:'.((int) config('filevault.max_size_kb', 10240)),
                'mimetypes:'.implode(',', config('filevault.allowed_mime_types', [])),
            ],
        ];
    }
}
