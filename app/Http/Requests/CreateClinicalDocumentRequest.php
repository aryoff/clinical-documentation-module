<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The authoring form. Authoring was gated by `permission:...documents.author`
 * middleware on create, store, edit and update together; each action now
 * carries the same question in a FormRequest so the publisher can see it —
 * see SignClinicalDocumentRequest for why middleware alone cannot.
 */
class CreateClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.documents.author') === true;
    }

    /**
     * The handoff is read from the query string and answered with a redirect
     * rather than a validation error, because arriving here without one is a
     * wrong turn rather than a malformed request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
