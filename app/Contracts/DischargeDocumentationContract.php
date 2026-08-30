<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * Whether this episode's discharge documentation is actually complete.
 *
 * The operative word is *actually*. A consumer asking "is there a discharge
 * summary" gets the answer a filing cabinet gives; what a ward needs before it
 * sends somebody home is whether the summary says the things a summary has to
 * say, was signed by somebody entitled to sign it, was explained to a named
 * person, and has not been overtaken by something clinical that happened
 * afterwards.
 *
 * The consumer gets a **provider-neutral outcome** — complete or not, what is
 * missing, and who owns finishing it — and never the clinical content. What the
 * discharge summary says about a patient's diagnoses is this context's, and a
 * ward that could read it through this boundary would be a ward that had
 * quietly become a clinical-records system.
 */
interface DischargeDocumentationContract
{
    public const CAPABILITY_ID = 'clinicaldocumentation.discharge-documentation';

    /** The template a discharge summary is authored against. */
    public const TEMPLATE = 'inpatient-discharge-summary';

    /**
     * The elements a discharge summary must carry, and who owns each.
     *
     * Published so a consumer's screen can list what is missing without
     * restating the list — a second copy would drift, and the copy that drifted
     * would be the one telling a ward it was finished.
     *
     * @return array<string, string> element key => the role that owns it
     */
    public function requiredElements(): array;

    /**
     * Start a discharge summary against an accepted handoff.
     *
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function draftSummary(array $command, string $actorId): array;

    /**
     * Sign it, if it says everything a discharge summary has to say.
     *
     * Refuses a summary missing any required element, naming them. A signature
     * on an incomplete summary is the failure this whole capability exists to
     * prevent: it converts an omission into a certification.
     *
     * @return array<string, mixed>
     */
    public function signSummary(string $documentId, string $actorId): array;

    /**
     * Record that the summary was explained to the patient or their
     * representative, in a stated language and with any support they needed.
     *
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    public function recordExplanation(array $command, string $actorId): array;

    /**
     * The provider-neutral completion outcome for one journey.
     *
     * @return array{
     *     complete: bool,
     *     document_id: string|null,
     *     template_version: string|null,
     *     author_name: string|null,
     *     signed_at: string|null,
     *     receipt: array<string, mixed>|null,
     *     missing: list<array{element: string, owner: string}>,
     *     superseded_by_later_evidence: bool,
     * }
     */
    public function describeCompletion(string $registrationId): array;
}
