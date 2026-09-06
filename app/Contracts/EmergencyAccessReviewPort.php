<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Contracts;

/**
 * This module's view of `hospitalcore.emergency-access-review`.
 *
 * Break-glass over a signed document is granted here and audited here — the
 * `cd_clinical_audit_events` row is this context's own evidence of what it let
 * somebody read, and it is not going anywhere. What this module stopped keeping
 * is the **review**.
 *
 * The ward has its own emergency route over the episode, and it used to run its
 * own review of it, so a facility carried two emergency-access queues with
 * nothing correlating them. A privacy officer wants one, and it is the
 * registry's: every context that can grant emergency access hard-requires the
 * registry, and the question is asked about a patient, whose identity the
 * registry owns.
 *
 * **Publish only.** There is deliberately no read operation. This module is one
 * of the readers the queue exists to police, and a reader that could pull the
 * queue back is one screen from being the desk that judges it.
 *
 * **Required**, and reached with `call` rather than `callIfAvailable`. An
 * emergency access this module could not publish would be one nobody can
 * review, and a break-glass with no review behind it is not a control.
 */
interface EmergencyAccessReviewPort
{
    /**
     * Publish one granted emergency access into the facility's queue.
     *
     * Carries the access and never the document: an id, a kind, a coarse scope
     * label, the reader, the moment and the stated reason. The reviewer judges
     * whether the glass should have been broken, which needs none of what was
     * behind it.
     *
     * @param  array<string, mixed>  $fact
     * @return array<string, mixed>
     */
    public function publish(array $fact): array;
}
