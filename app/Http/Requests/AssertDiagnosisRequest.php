<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asserting a diagnosis is the authoring authority, not a new one: the
 * clinician who signed the encounter document is the clinician who names what
 * they concluded from it. Declared here rather than in route middleware so
 * PopulateModuleMenuAndZiggyController strips the route — and with it the Show
 * page's diagnosis form — for an account that cannot author.
 */
class AssertDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.documents.author') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'coding_system' => ['required', 'string'],
            'code' => ['required', 'string'],
            'display' => ['required', 'string'],
            'assertion_type' => ['required', Rule::in(['initial', 'supplement', 'supersession'])],
            'note' => ['nullable', 'string'],
            // A supersession that does not say what it corrects would fork the
            // lineage rather than advance it, so the pairing is enforced here
            // as well as in the service.
            'supersedes_assertion_id' => ['nullable', 'uuid', Rule::requiredIf(fn (): bool => $this->input('assertion_type') === 'supersession')],
            'evidence_ids' => ['nullable', 'array'],
            'evidence_ids.*' => ['uuid'],
        ];
    }
}
