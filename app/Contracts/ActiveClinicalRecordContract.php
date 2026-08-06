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

    /** @return array<string, mixed> */
    public function archiveDocument(string $documentId, string $actorId): array;
}
