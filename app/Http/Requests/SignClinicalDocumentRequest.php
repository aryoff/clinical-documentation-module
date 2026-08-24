<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Signing was gated by `permission:clinicaldocumentation.documents.sign` alone,
 * which enforces correctly and tells the publisher nothing:
 * PopulateModuleMenuAndZiggyController reads a route's FormRequest::authorize()
 * and keeps any route that has none, so a Clinical Authority denial left the
 * Sign control on the draft form and answered the press with a 403.
 *
 * The middleware stays as defence in depth, as Modules/AGENTS.md allows; what
 * it must not be is the only gate.
 */
class SignClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clinicaldocumentation.documents.sign') === true;
    }

    /**
     * Signing carries no payload of its own — the draft is already stored, and
     * the controller refuses an empty one by reading the document rather than
     * the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
