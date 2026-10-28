<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates in authorize() rather than in route middleware, so the sidebar and Ziggy reflect it (Modules/AGENTS.md).
 * A plain FormRequest: Inertia form submissions break on an AJAX-aware one.
 */
class RecordRetentionScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.retention.schedule') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'minimum_years' => ['required', 'integer', 'min:1', 'max:200'],
            'rationale' => ['required', 'string', 'max:2000'],
        ];
    }
}
