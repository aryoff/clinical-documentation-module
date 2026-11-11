<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Lang;

abstract class ClinicalFormRequest extends FormRequest
{
    public function attributes(): array
    {
        return Lang::get("clinicaldocumentation::validation.attributes");
    }
}
