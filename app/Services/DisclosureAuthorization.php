<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\DisclosureAuthorizationContract;
use Modules\ClinicalDocumentation\Models\DisclosureConsent;
use Modules\ClinicalDocumentation\Models\DisclosureDecision;
use Modules\ClinicalDocumentation\Models\DisclosureRequest;
use Modules\ClinicalDocumentation\Models\DisclosureVerification;

/** Provider of `clinicaldocumentation.disclosure-authorization`: one exact version, one recorded answer, never permission by default. */
final class DisclosureAuthorization implements DisclosureAuthorizationContract
{
    public const BASES = ['patient_self_access', 'patient_authorization', 'representative_authority', 'statutory'];

    public function authorize(string $requestId, string $patientId, string $refType, string $refId, string $lineageId, bool $isCurrentVersion): array
    {
        $request = Str::isUuid($requestId) ? DisclosureRequest::query()->find($requestId) : null;
        if ($request === null || Str::lower($request->patient_id) !== Str::lower($patientId)) {
            return $this->refused('unknown_request');
        }

        return DB::transaction(function () use ($request, $refType, $refId, $lineageId, $isCurrentVersion): array {
            $verification = $this->latestVerification($request);
            $verdict = $this->evaluate($request, $verification, $refId, $lineageId, $isCurrentVersion);

            return $this->decide($request, $verification, $verdict, $refType, $refId, $lineageId, $isCurrentVersion);
        });
    }

    /**
     * @param  array{reason: string|null, basis: string|null, historical_copy: bool}  $verdict
     * @return array{authorized: bool, reason: string|null, authorization_id: string|null, basis: string|null, recipient_name: string|null, historical_copy: bool, request_id: string|null, ref_type?: string, ref_id?: string, lineage_id?: string}
     */
    private function decide(DisclosureRequest $request, ?DisclosureVerification $verification, array $verdict, string $refType, string $refId, string $lineageId, bool $isCurrentVersion): array
    {
        $decision = DisclosureDecision::create([
            'request_id' => $request->id,
            'verification_id' => $verification?->id,
            'consent_id' => $verification?->consent_id,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'lineage_id' => $lineageId,
            'current_version' => $isCurrentVersion,
            'historical_copy' => $verdict['historical_copy'],
            'basis' => $verdict['basis'],
            'outcome' => $verdict['reason'] === null ? 'granted' : 'refused',
            'reason' => $verdict['reason'],
            'decided_at' => now(),
        ]);

        return $verdict['reason'] === null
            ? $this->granted($request, $verdict['basis'], $verdict['historical_copy'], $decision)
            : $this->refused($verdict['reason'], $request->id);
    }

    public function recheck(string $authorizationId): array
    {
        $decision = Str::isUuid($authorizationId) ? DisclosureDecision::query()->find($authorizationId) : null;
        if ($decision === null || $decision->outcome !== 'granted') {
            return $this->refused('unknown_authorization');
        }
        $request = DisclosureRequest::query()->findOrFail($decision->request_id);
        // The verification the decision relied on, not whichever one was recorded last.
        $verification = DisclosureVerification::query()->find($decision->verification_id);
        $verdict = $this->evaluate($request, $verification, $decision->ref_id, $decision->lineage_id, $decision->current_version);

        return $verdict['reason'] === null
            ? $this->granted($request, $verdict['basis'], $verdict['historical_copy'], $decision)
            : $this->refused($verdict['reason'], $request->id);
    }

    /** @return array{reason: string|null, basis: string|null, historical_copy: bool} */
    private function evaluate(DisclosureRequest $request, ?DisclosureVerification $verification, string $refId, string $lineageId, bool $isCurrentVersion): array
    {
        $refuse = static fn (string $reason, ?string $basis = null): array => ['reason' => $reason, 'basis' => $basis, 'historical_copy' => false];

        if ($verification === null) {
            return $refuse('verification_missing');
        }
        $basis = $verification->basis;
        if (! in_array($basis, self::BASES, true)) {
            return $refuse('basis_unsupported');
        }
        if (! in_array($lineageId, (array) $request->scope_refs, true)) {
            return $refuse('request_scope_mismatch', $basis);
        }

        $problem = match ($basis) {
            'patient_self_access' => $this->selfAccessProblem($request, $verification),
            'patient_authorization' => $this->consentProblem($request, $verification, $lineageId),
            'representative_authority' => $this->recipientProblem($verification)
                ?? (in_array($verification->authority_evidence_kind, (array) config('clinicaldocumentation.disclosure.representative_evidence_kinds'), true)
                    ? $this->evidenceProblem($verification)
                    : 'authority_evidence_missing'),
            'statutory' => $this->recipientProblem($verification)
                ?? (in_array($verification->statutory_basis, (array) config('clinicaldocumentation.disclosure.statutory_bases'), true)
                    ? $this->evidenceProblem($verification)
                    : 'statutory_basis_unsupported'),
        };
        if ($problem !== null) {
            return $refuse($problem, $basis);
        }

        if (! $isCurrentVersion && ! in_array($refId, (array) $request->historical_copy_refs, true)) {
            return $refuse('historical_copy_not_authorized', $basis);
        }

        return ['reason' => null, 'basis' => $basis, 'historical_copy' => ! $isCurrentVersion];
    }

    /** Every route other than the patient's own turns on who the recipient is, so the recipient's identity must have been checked. */
    private function recipientProblem(DisclosureVerification $verification): ?string
    {
        return $verification->verified_identity_of === 'recipient' ? null : 'recipient_identity_not_verified';
    }

    private function latestVerification(DisclosureRequest $request): ?DisclosureVerification
    {
        return DisclosureVerification::query()->where('request_id', $request->id)->orderByDesc('verified_at')->orderByDesc('id')->first();
    }

    private function selfAccessProblem(DisclosureRequest $request, DisclosureVerification $verification): ?string
    {
        return $verification->verified_identity_of === 'patient' && Str::lower(trim($request->recipient_relationship)) === 'self'
            ? null
            : 'self_access_recipient_is_not_the_patient';
    }

    private function consentProblem(DisclosureRequest $request, DisclosureVerification $verification, string $lineageId): ?string
    {
        if ($problem = $this->recipientProblem($verification)) {
            return $problem;
        }
        // Locked while `authorize()` decides, so a withdrawal committing now is either seen here or waits for that decision.
        // `recheck()` is a point-in-time read: the consumer records its handover straight after it.
        $consent = $verification->consent_id === null ? null : DisclosureConsent::query()->lockForUpdate()->find($verification->consent_id);
        if ($consent === null || $consent->patient_id !== $request->patient_id) {
            return 'consent_missing';
        }
        if ($consent->withdrawn_at !== null) {
            return 'consent_withdrawn';
        }
        $today = now()->startOfDay();
        if ($today->lt($consent->valid_from->startOfDay()) || $today->gt($consent->valid_until->startOfDay())) {
            return 'consent_not_in_force';
        }
        if (Str::lower(trim($consent->recipient_name)) !== Str::lower(trim($request->recipient_name))) {
            return 'consent_recipient_mismatch';
        }
        if (Str::lower(trim($consent->purpose)) !== Str::lower(trim($request->purpose))) {
            return 'consent_purpose_mismatch';
        }
        if (! in_array($lineageId, (array) $consent->scope_refs, true)) {
            return 'consent_scope_mismatch';
        }

        return null;
    }

    /** A route that is not the patient's own consent keeps its evidence: what was shown, and where it is kept. */
    private function evidenceProblem(DisclosureVerification $verification): ?string
    {
        return trim((string) $verification->evidence_reference) !== ''
            ? null
            : 'authority_evidence_missing';
    }

    /** @return array{authorized: bool, reason: string|null, authorization_id: string|null, basis: string|null, recipient_name: string|null, historical_copy: bool, request_id: string|null, ref_type?: string, ref_id?: string, lineage_id?: string} */
    private function granted(DisclosureRequest $request, ?string $basis, bool $historicalCopy, DisclosureDecision $decision): array
    {
        return [
            'authorized' => true,
            'reason' => null,
            'authorization_id' => $decision->id,
            'basis' => $basis,
            'recipient_name' => $request->recipient_name,
            'historical_copy' => $historicalCopy,
            'request_id' => $request->id,
            // The exact version the decision covers, so a consumer can compare it with the one it is about to hand over.
            'ref_type' => $decision->ref_type,
            'ref_id' => $decision->ref_id,
            'lineage_id' => $decision->lineage_id,
        ];
    }

    /** @return array{authorized: bool, reason: string|null, authorization_id: string|null, basis: string|null, recipient_name: string|null, historical_copy: bool, request_id: string|null, ref_type?: string, ref_id?: string, lineage_id?: string} */
    private function refused(string $reason, ?string $requestId = null): array
    {
        return ['authorized' => false, 'reason' => $reason, 'authorization_id' => null, 'basis' => null, 'recipient_name' => null, 'historical_copy' => false, 'request_id' => $requestId];
    }
}
