<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An addendum is a second signature, gated apart from the first. Declared here
 * rather than in route middleware alone so PopulateModuleMenuAndZiggyController
 * can strip the route and the Show page's Amend control disappears with it —
 * see SignClinicalDocumentRequest for why middleware cannot do that.
 */
class AmendClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.documents.amend') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'encountered_at' => ['required', 'date'],
        ];
    }
}
