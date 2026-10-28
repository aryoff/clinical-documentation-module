<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates in authorize() so the sidebar and Ziggy reflect it (Modules/AGENTS.md). A plain FormRequest: Inertia form
 * submissions break on an AJAX-aware one.
 */
class DestroyClinicalRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.retention.destroy') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'dependencies_acknowledged' => ['required', 'accepted'],
        ];
    }
}
