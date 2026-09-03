<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Clinical Diagnosis Read is a record read, not a document read: it returns
 * what was concluded and when it changed, and never the signed note behind it.
 * It therefore consumes the same reading authority, and the service still
 * requires an accepted treatment handoff on top of it.
 */
class ViewDiagnosisLineageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.records.read') === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
