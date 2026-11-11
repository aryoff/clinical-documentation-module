<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

/** A plain FormRequest: Inertia form submissions break on an AJAX-aware one. */
class WithdrawDisclosureConsentRequest extends ClinicalFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.disclosure.manage') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
