<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The audit trail is the module's one menu-visible reviewer screen, so it gates
 * in authorize() rather than in route middleware: PopulateModuleMenuAndZiggyController
 * consults FormRequest::authorize() to decide what the sidebar may offer, and a
 * middleware-only gate would leave the entry visible and answer it with a 403.
 */
class ViewClinicalAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('clinicaldocumentation.audit.view');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'document_id' => ['sometimes', 'nullable', 'uuid'],
            'patient_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
