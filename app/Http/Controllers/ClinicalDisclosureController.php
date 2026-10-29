<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\ClinicalDocumentation\Http\Requests\RecordDisclosureConsentRequest;
use Modules\ClinicalDocumentation\Http\Requests\RecordDisclosureVerificationRequest;
use Modules\ClinicalDocumentation\Http\Requests\StoreDisclosureRequestRequest;
use Modules\ClinicalDocumentation\Http\Requests\WithdrawDisclosureConsentRequest;
use Modules\ClinicalDocumentation\Services\DisclosureRecords;

/** The Disclosure Officer's workflow. The decision itself is the `clinicaldocumentation.disclosure-authorization` capability. */
final class ClinicalDisclosureController extends Controller
{
    public function storeRequest(StoreDisclosureRequestRequest $request, DisclosureRecords $records): RedirectResponse
    {
        $records->recordRequest($request->validated(), (string) $request->user()->id);

        return back();
    }

    public function storeVerification(RecordDisclosureVerificationRequest $request, string $disclosureRequest, DisclosureRecords $records): RedirectResponse
    {
        $records->recordVerification($disclosureRequest, $request->validated(), (string) $request->user()->id);

        return back();
    }

    public function storeConsent(RecordDisclosureConsentRequest $request, DisclosureRecords $records): RedirectResponse
    {
        $records->recordConsent($request->validated(), (string) $request->user()->id);

        return back();
    }

    public function withdrawConsent(WithdrawDisclosureConsentRequest $request, string $consent, DisclosureRecords $records): RedirectResponse
    {
        $records->withdrawConsent($consent, $request->validated('reason'), (string) $request->user()->id);

        return back();
    }
}
