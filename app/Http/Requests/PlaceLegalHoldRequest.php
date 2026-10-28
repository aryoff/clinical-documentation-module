<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates in authorize() rather than in route middleware, so the sidebar and Ziggy reflect it (Modules/AGENTS.md).
 * A plain FormRequest: Inertia form submissions break on an AJAX-aware one.
 */
class PlaceLegalHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.retention.hold') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'scope' => ['required', 'in:patient,record_set'],
            'reason' => ['required', 'string', 'max:2000'],
            'record_refs' => ['required_if:scope,record_set', 'nullable', 'array', 'min:1', 'max:500'],
            'record_refs.*.type' => ['required', 'string', 'max:80'],
            'record_refs.*.id' => ['required', 'string', 'max:120'],
        ];
    }
}
