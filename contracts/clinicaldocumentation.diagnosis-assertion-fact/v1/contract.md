# Capability contract: `clinicaldocumentation.diagnosis-assertion-fact` v1

**Owner:** ClinicalDocumentation · **Interaction mode:** `async` · **Version:** 1.0.0

ClinicalDocumentation publishes an append-only fact each time a clinician
asserts a diagnosis, as the named event
`clinicaldocumentation.diagnosis-assertion-fact-published` carrying one array
argument. The event name is the capability ID, so a consumer registers an inbox
without naming a ClinicalDocumentation class. The payload is provider-neutral
scalars only:
`assertion_id`, `lineage_id`, `revision`, `document_id`, `registration_id`,
`patient_id`, `coding_system`, `code`, `display`, `assertion_type`
(`initial`, `supplement`, or `supersession`), `supersedes_assertion_id`,
`note`, `asserted_by`, `asserted_by_name`, and `asserted_at`.

A consumer keeps its own projection and must not read `cd_*` storage, import a
ClinicalDocumentation model, or ask this capability to mutate anything. There is
no correction fact and no deletion fact: a changed diagnosis arrives as a
`supersession` naming the assertion it replaces, so a consumer advances its
projection by appending rather than by rewriting.

The snapshot is deliberately complete. An external submission that is queued,
delayed, or retried is submitted from the payload it captured, so a later
supersession never changes the clinical meaning of a record already sent.
