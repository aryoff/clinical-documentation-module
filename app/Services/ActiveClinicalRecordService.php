<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Contracts\DiagnosisAssertionFactPublisher;
use Modules\ClinicalDocumentation\Models\AllergyAssertion;
use Modules\ClinicalDocumentation\Models\ClinicalAddendum;
use Modules\ClinicalDocumentation\Models\ClinicalArchivePackage;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Models\ClinicalHandoff;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;

class ActiveClinicalRecordService implements ActiveClinicalRecordContract
{
    /** @var list<string> */
    private const HANDOFF_OWNERS = ['emergency', 'outpatient', 'inpatient', 'operating-room', 'obstetrics', 'physical-therapy'];

    /** @var list<string> */
    private const ASSERTION_TYPES = ['initial', 'supplement', 'supersession'];

    /** @var list<string> */
    private const EVIDENCE_OWNERS = ['laboratory', 'radiology'];

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly DiagnosisAssertionFactPublisher $facts,
    ) {}

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

    /**
     * Record a Diagnosis Assertion into the patient's append-only lineage.
     *
     * An `initial` opens the care journey's first lineage. A `supplement` opens
     * a parallel one, because a second concurrent diagnosis is a new fact and
     * not a correction of the first. A `supersession` continues its
     * predecessor's lineage at the next revision, and may only name an
     * assertion that is still a head — superseding an already-corrected fact
     * would fork history rather than advance it.
     */
    public function assertDiagnosis(array $command, string $actorId): array
    {
        $document = $this->signedAuthorDocument($this->requiredString($command, 'document_id'), $actorId);
        foreach (['coding_system', 'code', 'display', 'assertion_type'] as $key) {
            $this->requiredString($command, $key);
        }
        if ($command['coding_system'] !== 'ICD-10' || preg_match('/^[A-TV-Z][0-9]{2}(?:\.[A-Z0-9]{1,4})?$/', $command['code']) !== 1) {
            throw new \InvalidArgumentException('Diagnosis Assertions require a valid ICD-10 code snapshot.');
        }
        $assertionType = $command['assertion_type'];
        if (!in_array($assertionType, self::ASSERTION_TYPES, true)) {
            throw new \InvalidArgumentException('A Diagnosis Assertion is initial, supplement, or supersession.');
        }
        $evidenceIds = $this->citedEvidenceIds($command, $document->patient_id);

        return DB::transaction(function () use ($assertionType, $command, $actorId, $document, $evidenceIds): array {
            $openedJourney = DiagnosisAssertion::query()
                ->where('registration_id', $document->registration_id)
                ->lockForUpdate()
                ->exists();

            if ($assertionType === 'initial' && $openedJourney) {
                throw new \LogicException('This care journey already carries an initial Diagnosis Assertion; supplement or supersede it instead.');
            }
            if ($assertionType !== 'initial' && !$openedJourney) {
                throw new \LogicException('The first Diagnosis Assertion for a care journey must be an initial assertion.');
            }

            $lineageId = null;
            $revision = 1;
            $predecessorId = null;

            if ($assertionType === 'supersession') {
                $predecessor = $this->supersededHead($this->requiredString($command, 'supersedes_assertion_id'), $document->patient_id);
                $lineageId = $predecessor->lineage_id;
                $revision = $predecessor->revision + 1;
                $predecessorId = $predecessor->id;
            }

            $assertion = DiagnosisAssertion::create([
                'lineage_id' => $lineageId,
                'document_id' => $document->id,
                'registration_id' => $document->registration_id,
                'patient_id' => $document->patient_id,
                'coding_system' => $command['coding_system'],
                'code' => $command['code'],
                'display' => $command['display'],
                'assertion_type' => $assertionType,
                'revision' => $revision,
                'supersedes_assertion_id' => $predecessorId,
                'evidence_refs' => $evidenceIds === [] ? null : $evidenceIds,
                'note' => $command['note'] ?? null,
                // Frozen where the caller supplied one. The ward's
                // external-transfer intake does, because an admission decided
                // on a transcribed referral has to record the credential the
                // transcriber relied on even if it later lapses (#275). The
                // ordinary authoring path supplies none, and that gap is named
                // on #259 rather than closed here.
                'clinical_authority_snapshot' => is_array($command['clinical_authority_snapshot'] ?? null)
                    ? $command['clinical_authority_snapshot']
                    : null,
                'asserted_by' => $actorId,
                'asserted_by_name' => $this->actorName($actorId),
                'asserted_at' => now(),
            ]);
            $this->audit('diagnosis_asserted', $actorId, $document->patient_id, $document->id, null, null, [
                'assertion_id' => $assertion->id,
                'lineage_id' => $assertion->lineage_id,
                'revision' => $assertion->revision,
                'assertion_type' => $assertion->assertion_type,
                'supersedes_assertion_id' => $predecessorId,
                'evidence_ids' => $evidenceIds,
            ]);

            // Integrations learn the diagnosis here, as scalars. The snapshot
            // is complete on purpose: a submission queued now and retried after
            // a later supersession must still say what was true when it was
            // asserted.
            $this->facts->publish(array_merge($this->assertionFact($assertion), [
                'supersedes_assertion_id' => $predecessorId,
            ]));

            return [
                'assertion_id' => $assertion->id,
                'lineage_id' => $assertion->lineage_id,
                'revision' => $assertion->revision,
                'clinical_authority_snapshot' => $assertion->clinical_authority_snapshot,
                'document_id' => $document->id,
                'patient_id' => $document->patient_id,
                'registration_id' => $document->registration_id,
                'code' => $assertion->code,
                'assertion_type' => $assertion->assertion_type,
            ];
        });
    }

    /**
     * The current heads only — the assertions a prescription is allowed to
     * cite. A superseded fact stays readable through the lineage, but it can no
     * longer justify a new order.
     */
    public function currentDiagnosisHeads(string $patientId, string $actorId, string $purpose): array
    {
        $this->assertTreatingAccess($patientId, $actorId);
        $purpose = $this->requiredPurpose($purpose);
        $this->audit('diagnosis_heads_read', $actorId, $patientId, null, null, $purpose);

        return [
            'patient_id' => $patientId,
            'purpose' => $purpose,
            'assertions' => $this->currentHeadFacts($patientId),
        ];
    }

    /**
     * The Clinical Diagnosis Read: every lineage, each revision in order, with
     * the evidence each assertion cited. It grants no document access.
     */
    public function diagnosisLineageForPatient(string $patientId, string $actorId, string $purpose): array
    {
        $this->assertTreatingAccess($patientId, $actorId);
        $purpose = $this->requiredPurpose($purpose);

        return $this->diagnosisLineage($patientId, $actorId, $purpose);
    }

    /**
     * The same read for a clinician taking the case over, anchored by the
     * originating clinician's accepted handoff rather than by one of their own.
     */
    public function diagnosisLineageForTakeover(string $patientId, string $actorId, string $authorizingActorId, string $handoffId, string $purpose): array
    {
        $this->assertAcceptedHandoff($patientId, $authorizingActorId, $handoffId);
        $purpose = $this->requiredPurpose($purpose);

        return $this->diagnosisLineage($patientId, $actorId, $purpose, [
            'authorized_by' => $authorizingActorId,
            'handoff_id' => $handoffId,
        ]);
    }

    /**
     * A result owner publishes an immutable finding. This is evidence a
     * clinician may cite; it is never itself a diagnosis, so it creates no
     * assertion and opens no lineage.
     */
    public function recordDiagnosticResultEvidence(array $command, string $actorId): array
    {
        foreach (['patient_id', 'source_owner', 'result_reference_id', 'coding_system', 'code', 'display'] as $key) {
            $this->requiredString($command, $key);
        }
        if (!in_array($command['source_owner'], self::EVIDENCE_OWNERS, true)) {
            throw new \InvalidArgumentException('Diagnostic Result Evidence must come from an eligible result owner.');
        }
        if (($command['observed_at'] ?? null) === null) {
            throw new \InvalidArgumentException('Diagnostic Result Evidence requires [observed_at].');
        }

        $evidence = DiagnosticResultEvidence::create([
            'patient_id' => $command['patient_id'],
            'registration_id' => $command['registration_id'] ?? null,
            'source_owner' => $command['source_owner'],
            'result_reference_id' => $command['result_reference_id'],
            'coding_system' => $command['coding_system'],
            'code' => $command['code'],
            'display' => $command['display'],
            'summary' => $command['summary'] ?? null,
            'observed_at' => $command['observed_at'],
            'released_by' => $actorId,
            'released_by_name' => $this->actorName($actorId),
            'recorded_at' => now(),
        ]);
        $this->audit('diagnostic_result_evidence_recorded', $actorId, $evidence->patient_id, null, null, null, [
            'evidence_id' => $evidence->id,
            'source_owner' => $evidence->source_owner,
            'result_reference_id' => $evidence->result_reference_id,
        ]);

        return $this->evidenceFact($evidence);
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
        // Current heads only. A superseded diagnosis is history, and history
        // must not read as a live reason to prescribe.
        $diagnoses = $this->currentHeadFacts($patientId);
        $this->audit('safety_facts_read', $actorId, $patientId, null, null, $purpose, $metadata);

        return ['patient_id' => $patientId, 'purpose' => $purpose, 'allergies' => $allergies, 'diagnoses' => $diagnoses];
    }

    /**
     * Validate that every cited evidence id exists and belongs to this patient.
     * Citing another patient's result would be a safety defect, not a typo.
     *
     * @param  array<string, mixed>  $command
     * @return list<string>
     */
    private function citedEvidenceIds(array $command, string $patientId): array
    {
        $ids = $command['evidence_ids'] ?? [];
        if ($ids === [] || $ids === null) {
            return [];
        }
        if (!is_array($ids) || array_filter($ids, 'is_string') !== $ids) {
            throw new \InvalidArgumentException('Diagnosis evidence citations must be a list of evidence IDs.');
        }

        $ids = array_values(array_unique($ids));
        $found = DiagnosticResultEvidence::query()
            ->whereKey($ids)
            ->where('patient_id', $patientId)
            ->pluck('id')
            ->all();
        if (count($found) !== count($ids)) {
            throw new \InvalidArgumentException('A Diagnosis Assertion may only cite Diagnostic Result Evidence recorded for the same patient.');
        }

        return $ids;
    }

    /** The predecessor a supersession may continue: this patient's, and still a head. */
    private function supersededHead(string $assertionId, string $patientId): DiagnosisAssertion
    {
        $predecessor = DiagnosisAssertion::query()
            ->whereKey($assertionId)
            ->where('patient_id', $patientId)
            ->first();
        if ($predecessor === null) {
            throw new \InvalidArgumentException('The superseded Diagnosis Assertion does not belong to this patient.');
        }
        if (DiagnosisAssertion::query()->where('supersedes_assertion_id', $predecessor->id)->exists()) {
            throw new \LogicException('That Diagnosis Assertion has already been superseded; supersede the current one instead.');
        }

        return $predecessor;
    }

    /** @return list<array<string, mixed>> */
    private function currentHeadFacts(string $patientId): array
    {
        return DiagnosisAssertion::query()
            ->where('patient_id', $patientId)
            ->currentHeads()
            ->orderBy('asserted_at')
            ->get()
            ->map(fn (DiagnosisAssertion $diagnosis): array => $this->assertionFact($diagnosis))
            ->all();
    }

    /** @param array<string, mixed> $metadata */
    private function diagnosisLineage(string $patientId, string $actorId, string $purpose, array $metadata = []): array
    {
        $assertions = DiagnosisAssertion::query()
            ->where('patient_id', $patientId)
            ->orderBy('asserted_at')
            ->get();

        $successorOf = $assertions
            ->filter(fn (DiagnosisAssertion $assertion): bool => $assertion->supersedes_assertion_id !== null)
            ->mapWithKeys(fn (DiagnosisAssertion $assertion): array => [$assertion->supersedes_assertion_id => $assertion->id])
            ->all();

        $evidence = $this->evidenceFactsById($assertions->pluck('evidence_refs')->filter()->flatten()->unique()->values()->all());

        $lineages = $assertions
            ->groupBy('lineage_id')
            ->map(fn ($group, string $lineageId): array => [
                'lineage_id' => $lineageId,
                'assertions' => $group
                    ->sortBy('revision')
                    ->values()
                    ->map(fn (DiagnosisAssertion $assertion): array => array_merge($this->assertionFact($assertion), [
                        'is_current' => !isset($successorOf[$assertion->id]),
                        'supersedes_assertion_id' => $assertion->supersedes_assertion_id,
                        'superseded_by' => $successorOf[$assertion->id] ?? null,
                        'evidence' => array_values(array_intersect_key($evidence, array_flip($assertion->evidence_refs ?? []))),
                    ]))
                    ->all(),
            ])
            ->values()
            ->all();

        $this->audit('diagnosis_lineage_read', $actorId, $patientId, null, null, $purpose, $metadata);

        return [
            'patient_id' => $patientId,
            'purpose' => $purpose,
            'current' => $this->currentHeadFacts($patientId),
            'lineages' => $lineages,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function evidenceFactsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DiagnosticResultEvidence::query()
            ->whereKey($ids)
            ->orderBy('observed_at')
            ->get()
            ->mapWithKeys(fn (DiagnosticResultEvidence $evidence): array => [$evidence->id => $this->evidenceFact($evidence)])
            ->all();
    }

    /** @return array<string, mixed> */
    private function assertionFact(DiagnosisAssertion $assertion): array
    {
        return [
            'assertion_id' => $assertion->id,
            'lineage_id' => $assertion->lineage_id,
            'revision' => $assertion->revision,
            'document_id' => $assertion->document_id,
            'registration_id' => $assertion->registration_id,
            'patient_id' => $assertion->patient_id,
            'coding_system' => $assertion->coding_system,
            'code' => $assertion->code,
            'display' => $assertion->display,
            'assertion_type' => $assertion->assertion_type,
            'note' => $assertion->note,
            // The credential the asserting clinician held at the moment they
            // asserted, where one was frozen. A reader auditing an admission
            // needs it on the fact rather than by joining back to the module
            // that took the copy.
            'clinical_authority_snapshot' => $assertion->clinical_authority_snapshot,
            'asserted_by' => $assertion->asserted_by,
            'asserted_by_name' => $assertion->asserted_by_name,
            'asserted_at' => $assertion->asserted_at->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceFact(DiagnosticResultEvidence $evidence): array
    {
        return [
            'evidence_id' => $evidence->id,
            'source_owner' => $evidence->source_owner,
            'result_reference_id' => $evidence->result_reference_id,
            'coding_system' => $evidence->coding_system,
            'code' => $evidence->code,
            'display' => $evidence->display,
            'summary' => $evidence->summary,
            'observed_at' => $evidence->observed_at->toAtomString(),
            'released_by' => $evidence->released_by,
        ];
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
