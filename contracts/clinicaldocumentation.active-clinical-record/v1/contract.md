# Capability contract: `clinicaldocumentation.active-clinical-record` v1

**Owner:** ClinicalDocumentation · **Interaction mode:** `sync` · **Version:** 1.0.0

ClinicalDocumentation alone owns signed clinical content, signed addenda,
Diagnosis Assertions, Allergy Assertions, clinical access evidence, and archive
release authority. Consumers exchange scalar IDs and arrays only. They must not
read or write `cd_*` storage, import module models, or infer authorization from
a queue, bed, or roster.

## Binding

The in-process seam is
`Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract`, bound
to `ActiveClinicalRecordService` by the module service provider. Root user IDs,
Hospital Registration IDs, and Patient Registry IDs are UUID strings.

## Operations

- `acceptHandoff(array $command): array` accepts explicit evidence from an
  eligible care owner (`emergency`, `outpatient`, `inpatient`,
  `operating-room`, `obstetrics`, or `physical-therapy`). It requires
  registration, patient, source-episode, recipient, and accepting actor IDs.
  The response contains an opaque `handoff_id`; only its recipient can author.
- `createDraft`, `updateDraft`, and `signDocument` create private drafts and
  immutable template-versioned signed documents. A signed document returns
  `status: signed` and cannot be changed or deleted.
- `createAddendum` and `signAddendum` create separately signed, reasoned
  corrections. They never replace or demote the source document.
- `readDocument(documentId, actorId, purpose)` permits an accepted treating
  recipient to read signed evidence and records an access event. Drafts remain
  author-private. `breakGlassRead` requires an emergency reason, records a
  correlation ID and security-review flag, and grants no authoring right.
- `assertDiagnosis` accepts an ICD-vocabulary snapshot (`coding_system`,
  `code`, `display`) cited to a signed document. `assertAllergy` requires
  substance, reaction, severity, verification status, and active state. Both
  are immutable ClinicalDocumentation facts and never create a charge.
- `safetyFactsForPatient(patientId, actorId, purpose)` returns the caller's
  purpose-scoped Allergy and Diagnosis Assertions only when the caller holds an
  accepted treatment handoff. Every read is audited.
- `archiveDocument` records a local integrity package and archive intent. When
  `medicalrecords.ciphertext-vault` is unavailable it returns
  `custody_state: local_retention`; discharge is never blocked. A resolved vault
  transitions the package to `pending_custody` until its own receipt contract
  verifies custody.

`safetyFactsForDelegatedPrescriber(patientId, actorId, authorizingActorId,
handoffId, purpose)` returns the same purpose-scoped safety facts to a delegated
prescriber when the authorizing actor is the accepted recipient of that exact
originating treatment handoff.
The delegated actor is audited, and this operation grants no document read or
clinical-document authoring access.

## Compatibility

Consumers declare `^1.0` and may rely only on documented request/response
keys. v1 adds fields and operations compatibly; weakening immutability,
handoff-gated authoring, reasoned Break-Glass, or purpose-scoped reads requires
a new major version.
