# Sign Clinical Documents and Correct Them by Addendum

Legacy SIMRS has no structured clinical reasoning at all. `FormRekamMedis.frm`,
`FormMonitoringRM.frm`, `FormHasilRadiologi.frm`, and `FormTopTenPenyakit.frm`
record the medical-records department's paper-era workflow: a folder is tracked,
a result is filed, a disease is counted. Contemporaneous digital clinical
authoring is therefore a deliberate Modern Expansion rather than parity work,
and nothing in the legacy source constrains its shape.

The first implementation chose `draft → submitted → superseded`. That model is
rejected. A submitted note that later becomes `superseded` is a record whose
meaning changes after the fact: the reader of a superseded note cannot tell
whether it was wrong, clarified, or merely followed by a later visit, and the
chain reads as a sequence of replacements rather than as what the author
actually knew at each point in time. A signed Clinical Document is instead
immutable the moment it is signed, and every correction, clarification, or
supplementation is a separately signed Clinical Addendum that preserves the
original, its reason, its author, the encounter time, the signing time, and the
authorization. The original is never demoted.

Documents are created from a versioned Clinical Document Template, and a signed
record stays bound to the template version it was signed against. SOAP is the
first template, not the model. Drafts are author-private; signing is what makes
a document clinical evidence readable by authorized treating staff. A draft
begun during active care may be signed after final discharge, and an authorized
addendum may follow much later, both carrying true encounter time and full
audit — neither reopens the Hospital Registration.

Authoring is gated by an explicit accepted Clinical Handoff. Holding a queue
position, occupying a bed, or being rostered to a ward is not authorization to
write in a patient's chart. ER, outpatient, and inpatient contexts keep their
own operational visit, queue, ward, transfer, and handoff records; only the
handoff they publish grants this context's authoring right.

## Structured facts move here

Diagnosis Assertions are ClinicalDocumentation-owned clinical facts linked to
the signed evidence that supports them. HospitalCore supplies the ICD
vocabulary and nothing else; it is not the global mutable diagnosis record. The
existing path where this module drives `HospitalCore\DiagnosisService` to write
`hc_visit_diagnoses` inverts that ownership and is prior art to remove. Allergy
Assertions are likewise owned here as structured facts with substance,
reaction, severity, verifier, and active state — the Patient Registry is not
their source, and registration, billing, and ward paths cannot edit them.

### Amended: a Diagnosis Assertion carries a lineage

*Amended on [#255](https://github.com/aryoff/boilerplate-laravel-react/issues/255).*

A diagnosis moves during a stay. The admitting impression narrows once a result
comes back, and a three-day inpatient course routinely ends on a different code
than it started on. The original text left only two ways to record that, and
both are wrong: assert a second unrelated fact, which reads as two concurrent
diseases, or edit the first, which this context forbids.

A Diagnosis Assertion therefore belongs to a **lineage**. A `supersession`
appends a successor assertion carrying its predecessor's `lineage_id` at the
next revision; a `supplement` opens a *parallel* lineage, because a genuinely
second diagnosis is a new fact rather than a correction. Nothing is edited and
nothing is deleted, and an assertion's head status is **derived** — an assertion
is current when nothing supersedes it — so there is no stored `superseded` flag
to drift from the facts.

This is not the `draft → submitted → superseded` document model this decision
rejected, and the distinction is the whole point. That model demoted a signed
*document* so that a reader could no longer tell what the author knew when they
signed it. Here every assertion stays readable at full fidelity with its author,
its time, and the evidence it cited; the lineage adds the one thing the addendum
chain could not express, which is *which* earlier fact a later fact corrects.
The Clinical Diagnosis Read returns each lineage in revision order for exactly
that reason.

A clinician cites evidence rather than being handed a diagnosis by a machine.
**Diagnostic Result Evidence** is an immutable finding published by a result
owner — Laboratory, Radiology — that an assertion may cite. Recording evidence
is never itself asserting: no ancillary module creates, supplements, or
supersedes a Diagnosis Assertion.

Signing a document, diagnosis, or allergy never creates a Billable Service
Fact. An ancillary provider owns its own order, execution, result, and charge,
and may cite a signed rationale as Clinical Evidence. Clinical Consumable
Orders and the Clinical Consumable Usage recorded on actual use belong here;
Warehouse owns the correlated batch custody and stock truth.

## EPrescription and MedicalRecords are independent

`module.json` currently hard-requires `MedicalRecords`, and the module embeds
EPrescription's `PrescriptionPanel` while EPrescription carries a
`soap_note_id` foreign key back. All three couplings are removed. This context
and EPrescription are independently installable: a guarded, provider-neutral
action may open the EPrescription-owned page with authorized registration and
document context, and a public Clinical Rationale Reference may link a
prescription to signed reasoning, but urgent prescribing works with no clinical
document and this module works with no prescriber.

MedicalRecords is optional and is a ciphertext-only vault. This context is the
sole key-holding gateway: it encrypts every Clinical Payload before it leaves
the application, holds the Clinical Record Archive Manifest that maps opaque
locators to real patients, and authorizes each bounded one-patient release. At
final discharge it sends an archive package asynchronously and reduces its own
retained record to metadata, references, and integrity evidence only after a
verified custody acknowledgement. A failed or absent archive means retry,
alert, or authorized exception under sealed Local Clinical Retention — it never
delays discharge or physical release, and later addenda append new packages
rather than overwriting custody.

## Status of prior art

`docs/PRD.md` described the superseded model — the `superseded` state machine,
`hc_visit_diagnoses` writes through HospitalCore, the embedded prescription
panel, and the `MedicalRecords` hard requirement — and has been removed rather
than revised. The target vocabulary is in `CONTEXT.md`; the dossier and
acceptance matrix that replace the PRD are recorded in decision ticket
[#23](https://github.com/aryoff/boilerplate-laravel-react/issues/23), with the
archive boundary in
[#24](https://github.com/aryoff/boilerplate-laravel-react/issues/24). Treat the
current tables, states, and cross-module relationships as scaffolding to
replace, not a foundation to extend. No implementation is authorized by this
decision.
