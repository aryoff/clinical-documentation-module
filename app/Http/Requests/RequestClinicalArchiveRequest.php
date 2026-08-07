<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates archive custody in authorize() so the Show page can offer the action
 * with route().has() and hide it from a user who does not hold the permission,
 * as the Permission-as-a-Proxy pattern in Modules/AGENTS.md describes.
 */
class RequestClinicalArchiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('clinicaldocumentation.archive.manage');
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [];
    }
}
