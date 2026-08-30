<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Contracts\DischargeDocumentationContract;
use Modules\ClinicalDocumentation\Models\ClinicalAddendum;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Models\ClinicalHandoff;
use Modules\ClinicalDocumentation\Models\DischargeReceipt;

/**
 * Whether an episode's discharge documentation is actually complete.
 *
 * Three decisions, and each is a refusal to accept a weaker answer than the one
 * a patient's safety turns on.
 *
 * **Presence is not completeness.** `signSummary()` validates the summary's
 * content against `requiredElements()` and refuses to sign one that is missing
 * any of them, naming what is absent and who owns it. A signature on an
 * incomplete summary is worse than no summary: it converts an omission into a
 * certification, and every downstream reader believes it.
 *
 * **Signing is not explaining.** The receipt is its own record, by a different
 * person at a different time, carrying the recipient, the language and any
 * accessibility support the explanation used. Folding the two into one
 * signature is how a hospital comes to certify that a patient had their
 * follow-up plan explained on the strength of a document they never saw.
 *
 * **Completeness expires.** A signed clinical document dated *after* the
 * summary means something happened that the summary does not describe — a new
 * diagnosis, a procedure, a deterioration — so the outcome reports
 * `superseded_by_later_evidence` and the episode is no longer ready. A signed
 * addendum on the summary is what restores it, which is exactly the mechanism
 * ADR 0002's immutability already provides: the summary is never rewritten.
 *
 * The consumer receives an outcome and never the content. What the summary says
 * about a patient's diagnoses is this context's.
 */
class DischargeDocumentationService implements DischargeDocumentationContract
{
    /**
     * What a discharge summary has to say, and whose job each part is.
     *
     * Owners are roles rather than accounts because the question a ward asks of
     * a missing element is "who do I chase", and the answer is a desk.
     */
    private const REQUIRED_ELEMENTS = [
        'admission_reason' => 'Responsible clinician',
        'diagnoses' => 'Responsible clinician',
        'findings' => 'Responsible clinician',
        'procedures_and_treatment' => 'Responsible clinician',
        'medication_changes' => 'Responsible clinician',
        'take_home_medicines' => 'Pharmacist',
        'condition_at_discharge' => 'Responsible clinician',
        'follow_up_plan' => 'Responsible clinician',
        'final_instructions' => 'Responsible clinician',
    ];

    public function requiredElements(): array
    {
        return self::REQUIRED_ELEMENTS;
    }

    public function draftSummary(array $command, string $actorId): array
    {
        $handoff = ClinicalHandoff::findOrFail($this->required($command, 'handoff_id'));

        if ($handoff->recipient_id !== $actorId) {
            throw new AuthorizationException('Only the accepted recipient of the handoff may author its discharge summary.');
        }

        $document = ClinicalDocument::create([
            'handoff_id' => $handoff->id,
            'registration_id' => $handoff->registration_id,
            'patient_id' => $handoff->patient_id,
            'template' => self::TEMPLATE,
            'template_version' => (string) ($command['template_version'] ?? '1.0'),
            'status' => 'draft',
            'author_id' => $actorId,
            'author_name' => $this->actorName($actorId),
            'payload' => (array) ($command['payload'] ?? []),
            'encountered_at' => Carbon::parse((string) ($command['encountered_at'] ?? Carbon::now())),
        ]);

        $this->audit('discharge_summary_drafted', $actorId, $document);

        return $this->summaryPayload($document);
    }

    public function signSummary(string $documentId, string $actorId): array
    {
        $document = ClinicalDocument::findOrFail($documentId);

        if ($document->template !== self::TEMPLATE) {
            throw new \InvalidArgumentException('That document is not an inpatient discharge summary.');
        }

        if ($document->author_id !== $actorId) {
            throw new AuthorizationException('A discharge summary is signed by the clinician responsible for it.');
        }

        $missing = $this->missingElements((array) $document->payload);

        if ($missing !== []) {
            // Named, with their owners. "Incomplete" sends a clinician to read
            // the whole form again; "no follow-up plan, owned by the
            // responsible clinician" sends them to write one.
            throw new \InvalidArgumentException(sprintf(
                'The discharge summary cannot be signed: %s.',
                implode('; ', array_map(
                    static fn (array $element): string => "[{$element['element']}] is missing ({$element['owner']})",
                    $missing,
                )),
            ));
        }

        $document->update([
            'status' => 'signed',
            'signed_at' => Carbon::now(),
            'signed_by' => $actorId,
            'signed_by_name' => $this->actorName($actorId),
        ]);

        $this->audit('discharge_summary_signed', $actorId, $document);

        return $this->summaryPayload($document->refresh());
    }

    public function recordExplanation(array $command, string $actorId): array
    {
        $document = ClinicalDocument::findOrFail($this->required($command, 'document_id'));

        if ($document->template !== self::TEMPLATE || $document->status !== 'signed') {
            throw new \InvalidArgumentException(
                'Only a signed discharge summary can be explained. Sign it first — explaining a draft explains '
                    .'something that may still change.',
            );
        }

        $kind = (string) ($command['recipient_kind'] ?? '');

        if (! in_array($kind, [DischargeReceipt::TO_PATIENT, DischargeReceipt::TO_REPRESENTATIVE], true)) {
            throw new \InvalidArgumentException('A discharge explanation is given to the patient or to an authorised representative.');
        }

        if ($kind === DischargeReceipt::TO_REPRESENTATIVE && blank($command['recipient_relationship'] ?? null)) {
            throw new \InvalidArgumentException('A representative receiving the explanation must be identified by their relationship.');
        }

        return DB::transaction(function () use ($command, $actorId, $document, $kind): array {
            $receipt = DischargeReceipt::create([
                'document_id' => $document->id,
                'registration_id' => $document->registration_id,
                'patient_id' => $document->patient_id,
                'recipient_kind' => $kind,
                'recipient_name' => $this->required($command, 'recipient_name'),
                'recipient_relationship' => $command['recipient_relationship'] ?? null,
                'language' => $command['language'] ?? null,
                'interpreter_name' => $command['interpreter_name'] ?? null,
                'accessibility_support' => $command['accessibility_support'] ?? null,
                'explanation_summary' => $this->required($command, 'explanation_summary'),
                'explained_at' => Carbon::now(),
                'explained_by' => $actorId,
                'explained_by_name' => $this->actorName($actorId),
            ]);

            $this->audit('discharge_summary_explained', $actorId, $document, [
                'receipt_id' => $receipt->id,
                'recipient_kind' => $receipt->recipient_kind,
            ]);

            return $this->receiptPayload($receipt);
        });
    }

    public function describeCompletion(string $registrationId): array
    {
        $summary = ClinicalDocument::query()
            ->where('registration_id', $registrationId)
            ->where('template', self::TEMPLATE)
            ->where('status', 'signed')
            ->orderByDesc('signed_at')
            ->first();

        if ($summary === null) {
            return $this->outcome(false, null, null, array_map(
                static fn (string $owner, string $element): array => ['element' => $element, 'owner' => $owner],
                array_values(self::REQUIRED_ELEMENTS),
                array_keys(self::REQUIRED_ELEMENTS),
            ));
        }

        $missing = $this->missingElements((array) $summary->payload);
        $receipt = DischargeReceipt::query()
            ->where('document_id', $summary->id)
            ->orderByDesc('explained_at')
            ->first();

        if ($receipt === null) {
            $missing[] = ['element' => 'explanation_receipt', 'owner' => 'Responsible clinician'];
        }

        $superseded = $this->supersededByLaterEvidence($summary);

        return $this->outcome(
            $missing === [] && ! $superseded,
            $summary,
            $receipt,
            $missing,
            $superseded,
        );
    }

    /**
     * Whether something clinical happened after this summary was last brought
     * up to date.
     *
     * "Last brought up to date" is the summary's signature, or its most recent
     * signed addendum — which is why a correction restores readiness without
     * anything being rewritten. Any other signed document dated later means the
     * summary no longer describes the episode, and the ward is told so rather
     * than discovering it when the patient comes back.
     */
    private function supersededByLaterEvidence(ClinicalDocument $summary): bool
    {
        $currentAsOf = ClinicalAddendum::query()
            ->where('document_id', $summary->id)
            ->whereNotNull('signed_at')
            ->max('signed_at');

        $currentAsOf = $currentAsOf === null
            ? $summary->signed_at
            : max(Carbon::parse($currentAsOf), $summary->signed_at);

        return ClinicalDocument::query()
            ->where('registration_id', $summary->registration_id)
            ->whereKeyNot($summary->id)
            ->where('status', 'signed')
            ->where('signed_at', '>', $currentAsOf)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{element: string, owner: string}>
     */
    private function missingElements(array $payload): array
    {
        $missing = [];

        foreach (self::REQUIRED_ELEMENTS as $element => $owner) {
            $value = $payload[$element] ?? null;

            // An element present but empty is missing. A summary carrying
            // `"follow_up_plan": ""` passes a key check and says nothing, which
            // is the failure mode a presence test cannot see.
            if ($value === null || (is_string($value) && trim($value) === '') || $value === []) {
                $missing[] = ['element' => $element, 'owner' => $owner];
            }
        }

        return $missing;
    }

    /**
     * @param  list<array{element: string, owner: string}>  $missing
     * @return array<string, mixed>
     */
    private function outcome(
        bool $complete,
        ?ClinicalDocument $summary,
        ?DischargeReceipt $receipt,
        array $missing,
        bool $superseded = false,
    ): array {
        return [
            'complete' => $complete,
            'document_id' => $summary?->id,
            'template_version' => $summary?->template_version,
            'author_name' => $summary?->author_name,
            'signed_by_name' => $summary?->signed_by_name,
            'signed_at' => $summary?->signed_at?->toAtomString(),
            'receipt' => $receipt === null ? null : $this->receiptPayload($receipt),
            'missing' => array_values($missing),
            'superseded_by_later_evidence' => $superseded,
        ];
    }

    /** @return array<string, mixed> */
    private function summaryPayload(ClinicalDocument $document): array
    {
        return [
            'document_id' => $document->id,
            'registration_id' => $document->registration_id,
            'template' => $document->template,
            'template_version' => $document->template_version,
            'status' => $document->status,
            'author_name' => $document->author_name,
            'signed_at' => $document->signed_at?->toAtomString(),
            'signed_by_name' => $document->signed_by_name,
        ];
    }

    /** @return array<string, mixed> */
    private function receiptPayload(DischargeReceipt $receipt): array
    {
        return [
            'receipt_id' => $receipt->id,
            'recipient_kind' => $receipt->recipient_kind,
            'recipient_name' => $receipt->recipient_name,
            'recipient_relationship' => $receipt->recipient_relationship,
            'language' => $receipt->language,
            'interpreter_name' => $receipt->interpreter_name,
            'accessibility_support' => $receipt->accessibility_support,
            'explained_at' => $receipt->explained_at->toAtomString(),
            'explained_by_name' => $receipt->explained_by_name,
        ];
    }

    /** @param array<string, mixed> $command */
    private function required(array $command, string $key): string
    {
        $value = $command[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Discharge documentation requires [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $action, string $actorId, ClinicalDocument $document, array $metadata = []): void
    {
        ClinicalAuditEvent::create([
            'patient_id' => $document->patient_id,
            'document_id' => $document->id,
            'subject_type' => 'clinical_document',
            'subject_id' => $document->id,
            'action' => $action,
            'actor_id' => $actorId,
            'causer_id' => $actorId,
            'actor_name' => $this->actorName($actorId),
            'metadata' => $metadata + ['template' => $document->template],
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function actorName(string $actorId): string
    {
        return User::query()->find($actorId)?->name ?? 'Unknown actor';
    }
}
