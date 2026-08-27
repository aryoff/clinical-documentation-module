<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\AllergyAssertion;
use Modules\ClinicalDocumentation\Models\ClinicalAddendum;
use Modules\ClinicalDocumentation\Models\ClinicalArchivePackage;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Models\ClinicalHandoff;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;

class ActiveClinicalRecordService implements ActiveClinicalRecordContract
{
    /** @var list<string> */
    private const HANDOFF_OWNERS = ['emergency', 'outpatient', 'inpatient', 'operating-room', 'obstetrics', 'physical-therapy'];

    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    public function acceptHandoff(array $command): array
    {
        foreach (['registration_id', 'patient_id', 'source_owner', 'source_reference_id', 'recipient_id', 'accepted_by'] as $key) {
            if (!is_string($command[$key] ?? null) || $command[$key] === '') {
                throw new \InvalidArgumentException("Clinical handoff requires [{$key}].");
            }
        }

        if (!in_array($command['source_owner'], self::HANDOFF_OWNERS, true)) {
            throw new \InvalidArgumentException('Clinical handoff source is not an eligible care context.');
        }

        return DB::transaction(function () use ($command): array {
            $handoff = ClinicalHandoff::create([
                'registration_id' => $command['registration_id'],
                'patient_id' => $command['patient_id'],
                'source_owner' => $command['source_owner'],
                'source_reference_id' => $command['source_reference_id'],
                'recipient_id' => $command['recipient_id'],
                'accepted_by' => $command['accepted_by'],
                'accepted_by_name' => $this->actorName($command['accepted_by']),
                'accepted_at' => now(),
            ]);

            $this->audit('handoff_accepted', $command['accepted_by'], $handoff->patient_id, null, null, null, [
                'handoff_id' => $handoff->id,
                'source_owner' => $handoff->source_owner,
                'source_reference_id' => $handoff->source_reference_id,
            ]);

            return [
                'handoff_id' => $handoff->id,
                'registration_id' => $handoff->registration_id,
                'patient_id' => $handoff->patient_id,
                'source_owner' => $handoff->source_owner,
                'recipient_id' => $handoff->recipient_id,
                'accepted_at' => $handoff->accepted_at->toAtomString(),
            ];
        });
    }

    public function createDraft(array $command, string $actorId): array
    {
        $handoff = ClinicalHandoff::findOrFail($this->requiredString($command, 'handoff_id'));
        $this->assertHandoffRecipient($handoff, $actorId);

        $template = $this->requiredString($command, 'template');
        $version = $this->requiredString($command, 'template_version');
        $payload = $command['payload'] ?? null;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Clinical document requires an object [payload].');
        }

        $document = ClinicalDocument::create([
            'handoff_id' => $handoff->id,
            'registration_id' => $handoff->registration_id,
            'patient_id' => $handoff->patient_id,
            'template' => $template,
            'template_version' => $version,
            'status' => 'draft',
            'author_id' => $actorId,
            'author_name' => $this->actorName($actorId),
            'payload' => $payload,
            'encountered_at' => $command['encountered_at'] ?? now(),
        ]);

        $this->audit('draft_created', $actorId, $document->patient_id, $document->id);

        return $this->documentPayload($document);
    }

    public function updateDraft(string $documentId, array $changes, string $actorId): array
    {
        $document = ClinicalDocument::findOrFail($documentId);
        $this->assertDocumentAuthor($document, $actorId);
        if ($document->status !== 'draft') {
            throw new \LogicException('Only a draft clinical document may be edited.');
        }

        $allowed = array_intersect_key($changes, array_flip(['payload', 'encountered_at']));
        if (array_key_exists('payload', $allowed) && !is_array($allowed['payload'])) {
            throw new \InvalidArgumentException('Clinical document payload must be an object.');
        }
        $document->fill($allowed)->save();
        $this->audit('draft_updated', $actorId, $document->patient_id, $document->id);

        return $this->documentPayload($document->refresh());
    }

    public function signDocument(string $documentId, string $actorId): array
    {
        return DB::transaction(function () use ($documentId, $actorId): array {
            $document = ClinicalDocument::query()->lockForUpdate()->findOrFail($documentId);
            $this->assertDocumentAuthor($document, $actorId);
            if ($document->status !== 'draft') {
                throw new \LogicException('Only a draft clinical document may be signed.');
            }
            if ($document->payload === []) {
                throw new \InvalidArgumentException('A clinical document cannot be signed with an empty payload.');
            }

            $document->fill([
                'status' => 'signed',
                'signed_at' => now(),
                'signed_by' => $actorId,
                'signed_by_name' => $this->actorName($actorId),
            ])->save();
            $this->audit('document_signed', $actorId, $document->patient_id, $document->id);

            return $this->documentPayload($document);
        });
    }

    public function createAddendum(array $command, string $actorId): array
    {
        $document = ClinicalDocument::findOrFail($this->requiredString($command, 'document_id'));
        $this->assertDocumentAuthor($document, $actorId);
        if ($document->status !== 'signed') {
            throw new \LogicException('An addendum can only cite a signed clinical document.');
        }

        $reason = $this->requiredString($command, 'reason');
        $payload = $command['payload'] ?? null;
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Clinical addendum requires an object [payload].');
        }

        $addendum = ClinicalAddendum::create([
            'document_id' => $document->id,
            'author_id' => $actorId,
            'author_name' => $this->actorName($actorId),
            'reason' => $reason,
            'payload' => $payload,
            'encountered_at' => $command['encountered_at'] ?? now(),
        ]);
        $this->audit('addendum_created', $actorId, $document->patient_id, $document->id, $addendum->id, $reason);

        return $this->addendumPayload($addendum);
    }

    public function signAddendum(string $addendumId, string $actorId): array
    {
        return DB::transaction(function () use ($addendumId, $actorId): array {
            $addendum = ClinicalAddendum::query()->lockForUpdate()->findOrFail($addendumId);
            if ($addendum->author_id !== $actorId) {
                throw new AuthorizationException('Only the addendum author can sign it.');
            }
            if ($addendum->signed_at !== null) {
                throw new \LogicException('A clinical addendum is already signed.');
            }

            $document = ClinicalDocument::findOrFail($addendum->document_id);
            if ($document->status !== 'signed') {
                throw new \LogicException('The cited clinical document must remain signed.');
            }

            $addendum->fill([
                'signed_at' => now(),
                'signed_by' => $actorId,
                'signed_by_name' => $this->actorName($actorId),
            ])->save();
            $this->audit('addendum_signed', $actorId, $document->patient_id, $document->id, $addendum->id);

            return $this->addendumPayload($addendum);
        });
    }

    public function readDocument(string $documentId, string $actorId, string $purpose): array
    {
        $document = ClinicalDocument::findOrFail($documentId);
        if ($document->status !== 'signed') {
            throw new AuthorizationException('Draft clinical documents are author-private.');
        }
        $this->assertTreatingAccess($document->patient_id, $actorId);
        $this->audit('document_read', $actorId, $document->patient_id, $document->id, null, $this->requiredPurpose($purpose));

        return $this->documentPayload($document);
    }

    public function breakGlassRead(string $documentId, string $actorId, string $reason): array
    {
        $document = ClinicalDocument::findOrFail($documentId);
        if ($document->status !== 'signed') {
            throw new AuthorizationException('Break-Glass cannot access a private draft.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('Break-Glass access requires an emergency reason.');
        }

        $correlationId = (string) \Illuminate\Support\Str::uuid();
        $this->audit('break_glass_read', $actorId, $document->patient_id, $document->id, null, $reason, [
            'security_review_required' => true,
        ], $correlationId);

        return array_merge($this->documentPayload($document), [
            'accessed_by' => $actorId,
            'correlation_id' => $correlationId,
            'security_review_required' => true,
        ]);
    }

    public function assertDiagnosis(array $command, string $actorId): array
    {
        $document = $this->signedAuthorDocument($this->requiredString($command, 'document_id'), $actorId);
        foreach (['coding_system', 'code', 'display', 'assertion_type'] as $key) {
            $this->requiredString($command, $key);
        }
        if ($command['coding_system'] !== 'ICD-10' || preg_match('/^[A-TV-Z][0-9]{2}(?:\.[A-Z0-9]{1,4})?$/', $command['code']) !== 1) {
            throw new \InvalidArgumentException('Diagnosis Assertions require a valid ICD-10 code snapshot.');
        }

        $assertion = DiagnosisAssertion::create([
            'document_id' => $document->id,
            'patient_id' => $document->patient_id,
            'coding_system' => $command['coding_system'],
            'code' => $command['code'],
            'display' => $command['display'],
            'assertion_type' => $command['assertion_type'],
            'note' => $command['note'] ?? null,
            'asserted_by' => $actorId,
            'asserted_by_name' => $this->actorName($actorId),
            'asserted_at' => now(),
        ]);
        $this->audit('diagnosis_asserted', $actorId, $document->patient_id, $document->id, null, null, ['assertion_id' => $assertion->id]);

        return ['assertion_id' => $assertion->id, 'document_id' => $document->id, 'patient_id' => $document->patient_id, 'code' => $assertion->code];
    }

    public function assertAllergy(array $command, string $actorId): array
    {
        $document = $this->signedAuthorDocument($this->requiredString($command, 'document_id'), $actorId);
        foreach (['substance', 'reaction', 'severity', 'verification_status'] as $key) {
            $this->requiredString($command, $key);
        }
        if (!in_array($command['severity'], ['mild', 'moderate', 'severe', 'unknown'], true)) {
            throw new \InvalidArgumentException('Allergy severity is invalid.');
        }
        if (!in_array($command['verification_status'], ['unverified', 'verified', 'refuted'], true)) {
            throw new \InvalidArgumentException('Allergy verification status is invalid.');
        }
        if (!is_bool($command['active'] ?? null)) {
            throw new \InvalidArgumentException('Allergy assertion requires boolean [active].');
        }

        $assertion = AllergyAssertion::create([
            'document_id' => $document->id,
            'patient_id' => $document->patient_id,
            'substance' => $command['substance'],
            'reaction' => $command['reaction'],
            'severity' => $command['severity'],
            'verification_status' => $command['verification_status'],
            'active' => $command['active'],
            'asserted_by' => $actorId,
            'asserted_by_name' => $this->actorName($actorId),
            'asserted_at' => now(),
        ]);
        $this->audit('allergy_asserted', $actorId, $document->patient_id, $document->id, null, null, ['assertion_id' => $assertion->id]);

        return ['assertion_id' => $assertion->id, 'document_id' => $document->id, 'patient_id' => $document->patient_id];
    }

    public function safetyFactsForPatient(string $patientId, string $actorId, string $purpose): array
    {
        $this->assertTreatingAccess($patientId, $actorId);
        $purpose = $this->requiredPurpose($purpose);
        return $this->safetyFacts($patientId, $actorId, $purpose);
    }

    public function safetyFactsForDelegatedPrescriber(string $patientId, string $actorId, string $authorizingActorId, string $handoffId, string $purpose): array
    {
        $this->assertAcceptedHandoff($patientId, $authorizingActorId, $handoffId);
        $purpose = $this->requiredPurpose($purpose);

        return $this->safetyFacts($patientId, $actorId, $purpose, [
            'authorized_by' => $authorizingActorId,
            'handoff_id' => $handoffId,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function safetyFacts(string $patientId, string $actorId, string $purpose, array $metadata = []): array
    {
        $allergies = AllergyAssertion::query()
            ->where('patient_id', $patientId)
            ->orderBy('asserted_at')
            ->get()
            ->map(fn (AllergyAssertion $allergy): array => [
                'assertion_id' => $allergy->id,
                'document_id' => $allergy->document_id,
                'substance' => $allergy->substance,
                'reaction' => $allergy->reaction,
                'severity' => $allergy->severity,
                'verification_status' => $allergy->verification_status,
                'active' => $allergy->active,
            ])
            ->all();
        $diagnoses = DiagnosisAssertion::query()
            ->where('patient_id', $patientId)
            ->orderBy('asserted_at')
            ->get()
            ->map(fn (DiagnosisAssertion $diagnosis): array => [
                'assertion_id' => $diagnosis->id,
                'document_id' => $diagnosis->document_id,
                'coding_system' => $diagnosis->coding_system,
                'code' => $diagnosis->code,
                'display' => $diagnosis->display,
                'assertion_type' => $diagnosis->assertion_type,
            ])
            ->all();
        $this->audit('safety_facts_read', $actorId, $patientId, null, null, $purpose, $metadata);

        return ['patient_id' => $patientId, 'purpose' => $purpose, 'allergies' => $allergies, 'diagnoses' => $diagnoses];
    }

    public function archiveDocument(string $documentId, string $actorId): array
    {
        $document = $this->signedAuthorDocument($documentId, $actorId);
        $state = $this->capabilities->isResolved('medicalrecords.ciphertext-vault') ? 'pending_custody' : 'local_retention';
        $package = ClinicalArchivePackage::firstOrCreate(
            ['document_id' => $document->id],
            [
                'patient_id' => $document->patient_id,
                'custody_state' => $state,
                'integrity_hash' => hash('sha256', json_encode($document->payload, JSON_THROW_ON_ERROR)),
                'requested_at' => now(),
            ],
        );
        $this->audit('archive_requested', $actorId, $document->patient_id, $document->id, null, null, ['custody_state' => $package->custody_state]);

        return ['document_id' => $document->id, 'package_id' => $package->id, 'custody_state' => $package->custody_state];
    }

    private function signedAuthorDocument(string $documentId, string $actorId): ClinicalDocument
    {
        $document = ClinicalDocument::findOrFail($documentId);
        $this->assertDocumentAuthor($document, $actorId);
        if ($document->status !== 'signed') {
            throw new \LogicException('Clinical safety facts and archive requests require a signed document.');
        }

        return $document;
    }

    private function assertDocumentAuthor(ClinicalDocument $document, string $actorId): void
    {
        if ($document->author_id !== $actorId) {
            throw new AuthorizationException('Only the clinical document author may perform this action.');
        }
    }

    private function assertHandoffRecipient(ClinicalHandoff $handoff, string $actorId): void
    {
        if ($handoff->recipient_id !== $actorId) {
            throw new AuthorizationException('Only the accepted clinical handoff recipient may author clinical documents.');
        }
    }

    private function assertTreatingAccess(string $patientId, string $actorId): void
    {
        if (ClinicalHandoff::query()->where('patient_id', $patientId)->where('recipient_id', $actorId)->exists()) {
            return;
        }

        throw new AuthorizationException('Clinical access requires an accepted treatment handoff or a reasoned Break-Glass action.');
    }

    private function assertAcceptedHandoff(string $patientId, string $actorId, string $handoffId): void
    {
        if (ClinicalHandoff::query()
            ->whereKey($handoffId)
            ->where('patient_id', $patientId)
            ->where('recipient_id', $actorId)
            ->whereNotNull('accepted_at')
            ->exists()) {
            return;
        }

        throw new AuthorizationException('Delegated safety access requires the originating accepted treatment handoff.');
    }

    private function requiredString(array $command, string $key): string
    {
        $value = $command[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Clinical record command requires [{$key}].");
        }

        return $value;
    }

    private function requiredPurpose(string $purpose): string
    {
        if (trim($purpose) === '') {
            throw new \InvalidArgumentException('Clinical access requires a purpose.');
        }

        return $purpose;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $action, string $actorId, ?string $patientId, ?string $documentId = null, ?string $addendumId = null, ?string $reason = null, array $metadata = [], ?string $correlationId = null): void
    {
        ClinicalAuditEvent::create([
            'patient_id' => $patientId,
            'document_id' => $documentId,
            'addendum_id' => $addendumId,
            'subject_type' => $addendumId !== null ? 'clinical_addendum' : ($documentId !== null ? 'clinical_document' : 'clinical_record'),
            'subject_id' => $addendumId ?? $documentId ?? $patientId,
            'action' => $action,
            'actor_id' => $actorId,
            'causer_id' => $actorId,
            'actor_name' => $this->actorName($actorId),
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function actorName(string $actorId): string
    {
        return User::query()->find($actorId)?->name ?? 'Unknown actor';
    }

    /** @return array<string, mixed> */
    private function documentPayload(ClinicalDocument $document): array
    {
        return [
            'document_id' => $document->id,
            'handoff_id' => $document->handoff_id,
            'registration_id' => $document->registration_id,
            'patient_id' => $document->patient_id,
            'template' => $document->template,
            'template_version' => $document->template_version,
            'status' => $document->status,
            'payload' => $document->payload,
            'encountered_at' => $document->encountered_at->toAtomString(),
            'signed_at' => $document->signed_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function addendumPayload(ClinicalAddendum $addendum): array
    {
        return [
            'addendum_id' => $addendum->id,
            'document_id' => $addendum->document_id,
            'reason' => $addendum->reason,
            'payload' => $addendum->payload,
            'encountered_at' => $addendum->encountered_at->toAtomString(),
            'signed_at' => $addendum->signed_at?->toAtomString(),
        ];
    }
}
