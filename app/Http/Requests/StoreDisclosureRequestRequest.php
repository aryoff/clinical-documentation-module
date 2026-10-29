<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A plain FormRequest: Inertia form submissions break on an AJAX-aware one. */
class StoreDisclosureRequestRequest extends FormRequest
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
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_relationship' => ['required', 'string', 'max:40'],
            'purpose' => ['required', 'string', 'max:200'],
            // The requested scope is part of the request; "everything" is not a scope.
            'scope_refs' => ['required', 'array', 'min:1', 'max:500'],
            'scope_refs.*' => ['string', 'max:120'],
            'historical_copy_refs' => ['nullable', 'array', 'min:1', 'max:50'],
            'historical_copy_refs.*' => ['string', 'max:120'],
        ];
    }
}
