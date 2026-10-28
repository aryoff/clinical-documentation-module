<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates in authorize() rather than in route middleware, so the sidebar and Ziggy reflect it (Modules/AGENTS.md).
 * A plain FormRequest: Inertia form submissions break on an AJAX-aware one.
 */
class RecordRetentionExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.retention.extend') === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'patient_id' => ['required', 'uuid'],
            'retain_until' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
