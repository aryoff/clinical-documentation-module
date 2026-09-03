<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

interface ActiveClinicalRecordContract
{
    /** @param array<string, mixed> $command
     *  @return array<string, mixed> */
    public function acceptHandoff(array $command): array;

    /** @param array<string, mixed> $command
     *  @return array<string, mixed> */
    public function createDraft(array $command, string $actorId): array;

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed> */
    public function updateDraft(string $documentId, array $changes, string $actorId): array;

    /** @return array<string, mixed> */
    public function signDocument(string $documentId, string $actorId): array;

    /** @param array<string, mixed> $command
     *  @return array<string, mixed> */
    public function createAddendum(array $command, string $actorId): array;

    /** @return array<string, mixed> */
    public function signAddendum(string $addendumId, string $actorId): array;

    /** @return array<string, mixed> */
    public function readDocument(string $documentId, string $actorId, string $purpose): array;

    /** @return array<string, mixed> */
    public function breakGlassRead(string $documentId, string $actorId, string $reason): array;

    /**
     * Record a Diagnosis Assertion into the patient's append-only lineage.
     *
     * $command carries document_id, coding_system, code, display, assertion_type
     * (`initial`, `supplement` or `supersession`), an optional note, an optional
     * list of evidence_ids, and — for a supersession only — the required
     * supersedes_assertion_id. The first assertion of a care journey must be
     * `initial`, and a supersession may only name an assertion that is still a
     * current head.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function assertDiagnosis(array $command, string $actorId): array;

    /**
     * The current heads only — the assertions a prescription may cite.
     *
     * @return array<string, mixed>
     */
    public function currentDiagnosisHeads(string $patientId, string $actorId, string $purpose): array;

    /**
     * The Clinical Diagnosis Read: every lineage with each revision in order and
     * the evidence it cited. It grants no clinical document access.
     *
     * @return array<string, mixed>
     */
    public function diagnosisLineageForPatient(string $patientId, string $actorId, string $purpose): array;

    /**
     * The Clinical Diagnosis Read for a clinician taking the case over, anchored
     * by the originating clinician's accepted handoff. Both actors are audited.
     *
     * @return array<string, mixed>
     */
    public function diagnosisLineageForTakeover(string $patientId, string $actorId, string $authorizingActorId, string $handoffId, string $purpose): array;

    /**
     * A result owner publishes an immutable finding a clinician may cite. It is
     * never itself a diagnosis and creates no assertion.
     *
     * @param array<string, mixed> $command
     * @return array<string, mixed>
     */
    public function recordDiagnosticResultEvidence(array $command, string $actorId): array;

    /** @param array<string, mixed> $command
     *  @return array<string, mixed> */
    public function assertAllergy(array $command, string $actorId): array;

    /** @return array<string, mixed> */
    public function safetyFactsForPatient(string $patientId, string $actorId, string $purpose): array;

    /**
     * Read safety facts as a delegated prescriber while auditing both actors.
     * The authorizing actor must hold the accepted treatment handoff; the
     * delegated actor receives safety facts only, never document access.
     *
     * @return array<string, mixed>
     */
    public function safetyFactsForDelegatedPrescriber(string $patientId, string $actorId, string $authorizingActorId, string $handoffId, string $purpose): array;

    /** @return array<string, mixed> */
    public function archiveDocument(string $documentId, string $actorId): array;
}
