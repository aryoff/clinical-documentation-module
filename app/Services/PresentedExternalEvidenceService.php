<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\FileVault;
use App\Models\User;
use App\Services\FileVaultService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Contracts\HospitalRegistrationPort;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Models\PresentedExternalEvidence;

final class PresentedExternalEvidenceService
{
    public const STAGING_PERMISSION = 'clinicaldocumentation.records.stage-external-evidence';

    public function __construct(
        private readonly FileVaultService $files,
        private readonly HospitalRegistrationPort $registrations,
    ) {}

    /** @return array<string, mixed>|null */
    public function activeRegistration(string $registrationId): ?array
    {
        $registration = $this->registrations->describe($registrationId);

        return is_array($registration) && ($registration['journey_status'] ?? null) === 'active'
            ? $registration
            : null;
    }

    public function stage(UploadedFile $file, string $registrationId, string $claim, User $actor): PresentedExternalEvidence
    {
        $registration = $this->activeRegistration($registrationId);
        if ($registration === null) {
            throw (new ModelNotFoundException())->setModel(PresentedExternalEvidence::class, [$registrationId]);
        }

        $fileVault = $this->files->storeUploadedFile(
            $file,
            $actor,
            self::STAGING_PERMISSION,
            [
                'context_protected' => true,
                'retention' => 'presented_external_evidence',
            ],
        );

        try {
            $this->files->setRetentionExpiry(
                $fileVault,
                now()->addDays(max(1, (int) config('clinicaldocumentation.presented_external_evidence_retention_days', 30))),
            );

            return DB::transaction(function () use ($fileVault, $registration, $claim, $actor): PresentedExternalEvidence {
                // Re-check and lock inside the transaction that creates the
                // custody record. A read-only describe before upload avoids
                // unnecessary work for an already-closed registration, while
                // this provider-side lock closes the release/stage race.
                $activeRegistration = $this->registrations->assertActive((string) $registration['registration_id']);
                if ($activeRegistration === null) {
                    throw (new ModelNotFoundException())->setModel(PresentedExternalEvidence::class, [(string) $registration['registration_id']]);
                }

                $evidence = PresentedExternalEvidence::create([
                    'registration_id' => (string) $activeRegistration['registration_id'],
                    'patient_id' => (string) $activeRegistration['patient_id'],
                    'file_vault_id' => $fileVault->id,
                    'claim' => trim($claim),
                    'staged_by' => (string) $actor->id,
                    'staged_by_name' => $actor->name,
                    'staged_at' => now(),
                ]);

                $fileVault->update([
                    'last_accessed_at' => now(),
                    'additional_data' => array_merge((array) $fileVault->additional_data, [
                        'presented_external_evidence_id' => $evidence->id,
                    ]),
                ]);

                $this->audit($evidence, $actor);

                return $evidence;
            });
        } catch (\Throwable $exception) {
            // Storage is deliberately outside the database transaction. If
            // metadata or its audit event cannot commit, remove the protected
            // file and its FileVault row after the rollback.
            $this->files->deleteFile($fileVault);

            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function unreviewedForRegistration(string $registrationId, string $patientId, User $actor): array
    {
        return PresentedExternalEvidence::query()
            ->where('registration_id', $registrationId)
            ->where('patient_id', $patientId)
            ->whereDoesntHave('reviews')
            ->latest('staged_at')
            ->get()
            ->map(fn (PresentedExternalEvidence $evidence): array => $this->payload($evidence, $actor))
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function unreviewedForDocument(ClinicalDocument $document, User $actor): array
    {
        return $this->unreviewedForRegistration($document->registration_id, $document->patient_id, $actor);
    }

    /** @return list<array<string, mixed>> */
    public function reviewedForDocument(ClinicalDocument $document, User $actor): array
    {
        return PresentedExternalEvidence::query()
            ->where('registration_id', $document->registration_id)
            ->where('patient_id', $document->patient_id)
            ->whereHas('reviews', fn ($query) => $query->where('document_id', $document->id))
            ->latest('staged_at')
            ->get()
            ->map(fn (PresentedExternalEvidence $evidence): array => [
                ...$this->payload($evidence, $actor),
                'incorporated' => $this->incorporationRecorded($evidence, $document),
            ])
            ->values()
            ->all();
    }

    public function review(string $evidenceId, string $documentId, User $actor): void
    {
        DB::transaction(function () use ($evidenceId, $documentId, $actor): void {
            $evidence = PresentedExternalEvidence::query()
                ->whereKey($evidenceId)
                ->lockForUpdate()
                ->firstOrFail();
            $document = ClinicalDocument::query()
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDocumentCanAuthorEvidence($document, $evidence, $actor);

            $existingReview = $evidence->reviews()->first();
            if ($existingReview !== null) {
                if ($existingReview->document_id !== $document->id) {
                    throw new AuthorizationException('Presented external evidence was reviewed in a different clinical document.');
                }

                return;
            }

            $review = $evidence->reviews()->create([
                'document_id' => $document->id,
                'patient_id' => $document->patient_id,
                'reviewed_by' => (string) $actor->id,
                'reviewed_by_name' => $actor->name,
                'reviewed_at' => now(),
            ]);

            ClinicalAuditEvent::create([
                'patient_id' => $document->patient_id,
                'document_id' => $document->id,
                'subject_type' => 'presented_external_evidence_review',
                'subject_id' => $review->id,
                'action' => 'external_evidence_reviewed',
                'actor_id' => (string) $actor->id,
                'causer_id' => (string) $actor->id,
                'actor_name' => $actor->name,
                'metadata' => [
                    'evidence_id' => $evidence->id,
                    'file_vault_id' => $evidence->file_vault_id,
                ],
                'occurred_at' => now(),
            ]);
        });
    }

    public function incorporate(string $evidenceId, string $documentId, User $actor): void
    {
        DB::transaction(function () use ($evidenceId, $documentId, $actor): void {
            $evidence = PresentedExternalEvidence::query()
                ->whereKey($evidenceId)
                ->lockForUpdate()
                ->firstOrFail();
            $document = ClinicalDocument::query()
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDocumentCanAuthorEvidence($document, $evidence, $actor);

            if (!$evidence->reviews()->where('document_id', $document->id)->exists()) {
                throw new AuthorizationException('Presented external evidence must be reviewed before incorporation is recorded.');
            }

            $alreadyIncorporated = ClinicalAuditEvent::query()
                ->where('document_id', $document->id)
                ->where('subject_type', 'presented_external_evidence_incorporation')
                ->where('subject_id', $evidence->id)
                ->where('action', 'external_evidence_incorporated')
                ->exists();

            if ($alreadyIncorporated) {
                return;
            }

            ClinicalAuditEvent::create([
                'patient_id' => $document->patient_id,
                'document_id' => $document->id,
                'subject_type' => 'presented_external_evidence_incorporation',
                'subject_id' => $evidence->id,
                'action' => 'external_evidence_incorporated',
                'actor_id' => (string) $actor->id,
                'causer_id' => (string) $actor->id,
                'actor_name' => $actor->name,
                'metadata' => [
                    'evidence_id' => $evidence->id,
                    'file_vault_id' => $evidence->file_vault_id,
                    'reviewed_in_document_id' => $document->id,
                ],
                'occurred_at' => now(),
            ]);
        });
    }

    public function fileFor(string $evidenceId, User $actor): FileVault
    {
        $evidence = PresentedExternalEvidence::query()->findOrFail($evidenceId);
        $draft = $this->authoredDraftFor($evidence, $actor);
        $canStage = $actor->can(self::STAGING_PERMISSION) && $evidence->staged_by === (string) $actor->id;

        if (!$canStage && $draft === null) {
            throw new AuthorizationException('You may not access this presented external evidence.');
        }

        $file = FileVault::query()->findOrFail($evidence->file_vault_id);
        $file->update(['last_accessed_at' => now()]);
        $this->auditFileRead($evidence, $actor, $draft?->id);

        return $file;
    }

    /** @return array<string, mixed> */
    private function payload(PresentedExternalEvidence $evidence, User $actor): array
    {
        $file = FileVault::query()->find($evidence->file_vault_id);

        return [
            'id' => $evidence->id,
            'registration_id' => $evidence->registration_id,
            'patient_id' => $evidence->patient_id,
            'claim' => $evidence->claim,
            'staged_by_name' => $evidence->staged_by_name,
            'staged_at' => $evidence->staged_at?->toAtomString(),
            'original_filename' => $file?->original_filename,
            'file_url' => route('clinicaldocumentation.presented-external-evidence.file', $evidence->id),
            'can_open_file' => $this->canOpenFile($evidence, $actor),
        ];
    }

    private function canOpenFile(PresentedExternalEvidence $evidence, User $actor): bool
    {
        return ($actor->can(self::STAGING_PERMISSION) && $evidence->staged_by === (string) $actor->id)
            || $this->authoredDraftFor($evidence, $actor) !== null;
    }

    private function authoredDraftFor(PresentedExternalEvidence $evidence, User $actor): ?ClinicalDocument
    {
        if (!$actor->can('clinicaldocumentation.documents.author')) {
            return null;
        }

        return ClinicalDocument::query()
            ->where('registration_id', $evidence->registration_id)
            ->where('patient_id', $evidence->patient_id)
            ->where('author_id', (string) $actor->id)
            ->where('status', 'draft')
            ->first();
    }

    private function assertDocumentCanAuthorEvidence(ClinicalDocument $document, PresentedExternalEvidence $evidence, User $actor): void
    {
        if ($document->author_id !== (string) $actor->id) {
            throw new AuthorizationException('Only the clinical document author can review presented external evidence.');
        }
        if ($document->status !== 'draft') {
            throw new AuthorizationException('Presented external evidence can only be reviewed while authoring a draft.');
        }
        if ($document->registration_id !== $evidence->registration_id || $document->patient_id !== $evidence->patient_id) {
            throw new AuthorizationException('Presented external evidence belongs to a different clinical journey.');
        }
    }

    private function auditFileRead(PresentedExternalEvidence $evidence, User $actor, ?string $documentId): void
    {
        ClinicalAuditEvent::create([
            'patient_id' => $evidence->patient_id,
            'document_id' => $documentId,
            'subject_type' => 'presented_external_evidence_file',
            'subject_id' => $evidence->id,
            'action' => 'external_evidence_file_read',
            'actor_id' => (string) $actor->id,
            'causer_id' => (string) $actor->id,
            'actor_name' => $actor->name,
            'metadata' => ['file_vault_id' => $evidence->file_vault_id],
            'occurred_at' => now(),
        ]);
    }

    private function incorporationRecorded(PresentedExternalEvidence $evidence, ClinicalDocument $document): bool
    {
        return ClinicalAuditEvent::query()
            ->where('document_id', $document->id)
            ->where('subject_type', 'presented_external_evidence_incorporation')
            ->where('subject_id', $evidence->id)
            ->where('action', 'external_evidence_incorporated')
            ->exists();
    }

    private function audit(PresentedExternalEvidence $evidence, User $actor): void
    {
        ClinicalAuditEvent::create([
            'patient_id' => $evidence->patient_id,
            'subject_type' => 'presented_external_evidence',
            'subject_id' => $evidence->id,
            'action' => 'external_evidence_staged',
            'actor_id' => (string) $actor->id,
            'causer_id' => (string) $actor->id,
            'actor_name' => $actor->name,
            'metadata' => ['file_vault_id' => $evidence->file_vault_id],
            'occurred_at' => now(),
        ]);
    }
}
