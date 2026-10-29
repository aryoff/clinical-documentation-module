<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ClinicalDocumentation\Services\DisclosureAuthorization;

/** A plain FormRequest: Inertia form submissions break on an AJAX-aware one. */
class RecordDisclosureVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.disclosure.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'basis' => ['required', Rule::in(DisclosureAuthorization::BASES)],
            'verified_identity_of' => ['required', 'in:patient,recipient'],
            'method' => ['required', 'string', 'max:80'],
            'consent_id' => ['required_if:basis,patient_authorization', 'nullable', 'uuid'],
            'authority_evidence_kind' => ['required_if:basis,representative_authority', 'nullable', Rule::in((array) config('clinicaldocumentation.disclosure.representative_evidence_kinds'))],
            'statutory_basis' => ['required_if:basis,statutory', 'nullable', Rule::in((array) config('clinicaldocumentation.disclosure.statutory_bases'))],
            'evidence_reference' => ['required', 'nullable', 'string', 'max:200'],
        ];
    }
}
