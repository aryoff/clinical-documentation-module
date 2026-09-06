<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Records the reasoned emergency read and its responder permission. */
class BreakGlassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.records.break-glass') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            // Optional, and it is the emergency rather than this read: a
            // clinician who already broke glass elsewhere in the same
            // emergency carries its id here so the facility's review queue
            // shows one emergency rather than two coincidences (#251).
            //
            // Not validated against an existing emergency, deliberately. This
            // module cannot read the queue, and a lookup that could tell a
            // caller whether an id exists would answer questions about other
            // people's emergency access.
            'correlation_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
