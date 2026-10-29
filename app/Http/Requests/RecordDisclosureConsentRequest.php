<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only a consent to disclose can be recorded. Registration and treatment consent are different consents, so they are
 * refused here rather than accepted and ignored. A plain FormRequest: Inertia form submissions break on an AJAX-aware one.
 */
class RecordDisclosureConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.disclosure.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:disclosure'],
            'recipient_name' => ['required', 'string', 'max:120'],
            'purpose' => ['required', 'string', 'max:200'],
            // A consent names the results it covers; "everything" is not a scope.
            'scope_refs' => ['required', 'array', 'min:1', 'max:500'],
            'scope_refs.*' => ['string', 'max:120'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
            'evidence_reference' => ['required', 'string', 'max:200'],
        ];
    }
}
