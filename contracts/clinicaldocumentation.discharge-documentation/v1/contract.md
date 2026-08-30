# Capability contract: `clinicaldocumentation.discharge-documentation` v1

**Owner:** ClinicalDocumentation · **Interaction Mode:** `sync` · **Version:** 1.0.0

This capability answers whether an episode's discharge documentation is
**actually complete** — not whether a document exists.

It is a separate capability from `clinicaldocumentation.active-clinical-record`
because its consumer is different. A ward asks whether the paperwork is finished
so it can decide whether a patient may leave; it must never be able to read what
the paperwork says. A consumer of this capability receives an outcome and a list
of what is missing, and no clinical content at any point.

## Operations

### `requiredElements(): array`

The elements a discharge summary must carry, as `element => owning role`.
Published so a consumer's screen can list what is outstanding without keeping
its own copy of the list — a second copy would drift, and the copy that drifted
would be the one telling a ward it was finished.

### `draftSummary(array $command, string $actorId): array`

Starts a summary against an accepted handoff. Only the handoff's recipient may
author it.

### `signSummary(string $documentId, string $actorId): array`

Validates the summary's **content** and refuses to sign one missing any required
element, naming each and its owner. A signature on an incomplete summary is
worse than no summary: it converts an omission into a certification that every
downstream reader believes.

An element that is present but empty counts as missing.

### `recordExplanation(array $command, string $actorId): array`

Records that the signed summary was explained, to whom, in what language, and
with what accessibility support. Signing and explaining are separate acts by
different people at different times; only a **signed** summary can be explained,
because explaining a draft explains something that may still change.

The recipient is the patient or an authorised representative, and a
representative must be identified by their relationship.

### `describeCompletion(string $registrationId): array`

The provider-neutral outcome:

| Key | Meaning |
| --- | --- |
| `complete` | every required element present, signed, explained, and not superseded |
| `document_id`, `template_version`, `author_name`, `signed_by_name`, `signed_at` | the audit trail a release record cites |
| `receipt` | who the explanation was given to, when, and how |
| `missing` | `[{element, owner}]` — what is outstanding and who to chase |
| `superseded_by_later_evidence` | a signed clinical document postdates the summary |

**Completeness expires.** A signed clinical document dated after the summary
means something happened the summary does not describe — a new diagnosis, a
procedure, a deterioration — so the outcome stops reporting complete. A signed
**addendum** on the summary restores it, which is the mechanism the immutability
rule already provides: the summary is never rewritten.

## What a consumer must not do

**Do not read the summary's payload.** This capability never returns it, and a
consumer that reached for the document through another route would have made
itself a clinical-records system.

**Do not treat an absent provider as completeness.** A composition without this
context cannot produce a discharge summary at all; a consumer must decide what
that means for its own workflow rather than defaulting to "finished".

## Compatibility

Consumers declare `^1.0`. Within v1 the operation names, the outcome keys and
the `{element, owner}` shape are stable; elements may be added to
`requiredElements()`, which is why a consumer must render the list rather than
hardcode it. Returning `complete: true` for a summary missing an element,
unsigned, unexplained, or superseded is a breaking change.
