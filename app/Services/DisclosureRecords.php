<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Models\DisclosureConsent;
use Modules\ClinicalDocumentation\Models\DisclosureRequest;
use Modules\ClinicalDocumentation\Models\DisclosureVerification;

/** What a Disclosure Officer records: the request, the recipient verification and the patient's disclosure consent. */
final class DisclosureRecords
{
    /** @param array<string, mixed> $data */
    public function recordRequest(array $data, string $actorId): DisclosureRequest
    {
        return DisclosureRequest::create([
            // Ids are stored lower-case so every comparison of them is one comparison.
            'patient_id' => Str::lower($data['patient_id']),
            'recipient_name' => $data['recipient_name'],
            'recipient_relationship' => $data['recipient_relationship'],
            'purpose' => $data['purpose'],
            'scope_refs' => $data['scope_refs'],
            'historical_copy_refs' => $data['historical_copy_refs'] ?? null,
            'requested_by' => $actorId,
            'requested_by_name' => $this->name($actorId),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function recordVerification(string $requestId, array $data, string $actorId): DisclosureVerification
    {
        $request = DisclosureRequest::query()->findOrFail($requestId);
        if ($data['basis'] === 'patient_authorization') {
            $consent = DisclosureConsent::query()->find($data['consent_id'] ?? null);
            if ($consent === null || $consent->patient_id !== $request->patient_id) {
                throw ValidationException::withMessages(['consent_id' => __('clinicaldocumentation::messages.disclosure_consent_not_found')]);
            }
        }

        return DisclosureVerification::create([
            'request_id' => $request->id,
            'basis' => $data['basis'],
            'verified_identity_of' => $data['verified_identity_of'],
            'method' => $data['method'],
            'consent_id' => $data['basis'] === 'patient_authorization' ? $data['consent_id'] : null,
            'authority_evidence_kind' => $data['basis'] === 'representative_authority' ? $data['authority_evidence_kind'] : null,
            'statutory_basis' => $data['basis'] === 'statutory' ? $data['statutory_basis'] : null,
            // Only the evidence the basis relied on is kept: this row is the proof of why a result was handed over.
            'evidence_reference' => in_array($data['basis'], ['representative_authority', 'statutory'], true) ? $data['evidence_reference'] : null,
            'verified_by' => $actorId,
            'verified_by_name' => $this->name($actorId),
            'verified_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function recordConsent(array $data, string $actorId): DisclosureConsent
    {
        return DisclosureConsent::create([
            'patient_id' => Str::lower($data['patient_id']),
            'recipient_name' => $data['recipient_name'],
            'purpose' => $data['purpose'],
            'scope_refs' => $data['scope_refs'],
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'],
            'evidence_reference' => $data['evidence_reference'],
            'recorded_by' => $actorId,
            'recorded_by_name' => $this->name($actorId),
        ]);
    }

    public function withdrawConsent(string $consentId, string $reason, string $actorId): DisclosureConsent
    {
        return DB::transaction(function () use ($consentId, $reason, $actorId): DisclosureConsent {
            $consent = DisclosureConsent::query()->lockForUpdate()->findOrFail($consentId);
            if ($consent->withdrawn_at !== null) {
                return $consent;
            }
            $consent->update(['withdrawn_at' => now(), 'withdrawn_by' => $actorId, 'withdrawn_by_name' => $this->name($actorId), 'withdrawal_reason' => $reason]);

            return $consent;
        });
    }

    private function name(string $actorId): ?string
    {
        return User::query()->whereKey($actorId)->value('name');
    }
}
