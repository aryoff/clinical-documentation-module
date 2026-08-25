<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A treating reader or an emergency responder may reach the document action.
 * The controller still enforces the treating relationship and redirects a
 * responder without one to the reasoned Break-Glass path.
 */
class ViewClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny([
            'clinicaldocumentation.records.read',
            'clinicaldocumentation.records.break-glass',
        ]) === true;
    }

    public function mayBreakGlass(): bool
    {
        return $this->user()?->can('clinicaldocumentation.records.break-glass') === true;
    }

    public function mayRequestArchive(): bool
    {
        return $this->user()?->can('clinicaldocumentation.archive.manage') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
