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

    /** @param array<string, mixed> $command
     *  @return array<string, mixed> */
    public function assertDiagnosis(array $command, string $actorId): array;

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
