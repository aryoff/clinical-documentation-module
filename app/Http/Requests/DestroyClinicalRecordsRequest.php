<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\ClinicalDocumentation\Services\DestructionEnablement;

/**
 * Gates in authorize() so the sidebar and Ziggy reflect it (Modules/AGENTS.md). A plain FormRequest: Inertia form
 * submissions break on an AJAX-aware one.
 */
class DestroyClinicalRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Destruction is off unless the deployment switched it on: a feature flag gates here, not in the route list.
        return app(DestructionEnablement::class)->enabled() && $this->user()?->can('clinicaldocumentation.retention.destroy') === true;
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
