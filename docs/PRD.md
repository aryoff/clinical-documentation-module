# PRD: ClinicalDocumentation Module

> Point-of-care SOAP note documentation for doctors and nurses against active patient registrations (ER, Inpatient, Outpatient).
> Acts as the upstream clinical reasoning layer — notes drive diagnosis recording (via HospitalCore `DiagnosisService`) and are optionally linked to EPrescriptions.
> Depends on `HospitalCore` (registrations, patients, DiagnosisService) and `EPrescription` (embedded `<PrescriptionPanel />`).
> 
> **Note**: For future strict compliance environments, a Zero-Trust encrypted architecture for SOAP notes is documented in `Modules/HospitalCore/docs/PRD_ENCRYPTED_RECORDS.md`.

---

## Problem Statement

In the current VB6 environment, clinical documentation (SOAP notes) is entirely paper-based. Doctors and nurses write handwritten notes on physical patient folders; there is no structured digital capture of clinical reasoning. This creates several issues:

- There is no structured digital record of what a doctor assessed and planned during a visit — the clinical reasoning behind a prescription or a diagnosis is not captured anywhere in the system.
- Nurses and doctors in ER, inpatient wards, and outpatient polyclinics have no shared digital view of the patient's current SOAP notes; staff must physically locate the paper folder.
- Amendments and corrections to clinical notes leave no audit trail — a correction is a manual cross-out on paper with no visibility into what changed or who changed it.
- Vital signs recorded on paper cannot be queried or trended across visits.
- There is no linkage between a doctor's written plan and the prescription that resulted from it — prescriptions float as standalone orders with no documented clinical reasoning.
- Government accreditation (SNARS) requires documented SOAP notes per visit with a clear amendment trail; the current paper system cannot demonstrate this programmatically.

## Solution

Create a **ClinicalDocumentation** module that provides a structured digital SOAP note workflow:

1. **SOAP Note Creation** — Doctors and nurses write structured SOAP notes (Subjective, Objective, Assessment, Plan) against an active patient registration. Vital signs are captured as structured JSON within the note.

2. **State Machine** — Notes follow a defined lifecycle: `draft` → `submitted` → `superseded`. Draft notes are visible only to the author; submitted notes are visible to all treating staff on the registration.

3. **Amendment Chain** — A submitted note can be formally amended. The amendment creates a new `draft` note pre-filled from the original, with an `amended_from_id` self-FK linking the chain. When the amendment is submitted, the original transitions to `superseded` and remains visible with a superseded marker.

4. **Diagnosis Recording** — The Assessment section drives ICD code selection. On submit, selected ICD codes are written to `hc_visit_diagnoses` via `HospitalCore\DiagnosisService`. ClinicalDocumentation owns the UI; HospitalCore owns the data.

5. **EPrescription Integration** — The Plan section embeds `<PrescriptionPanel mode="prescribe" />` inline. Any prescription created from within the SOAP note editor is auto-linked via `epresc_prescriptions.soap_note_id`. The link is nullable — emergency prescriptions can exist without a SOAP note.

6. **Audit Trail** — Full history of note creation, submission, and amendment with timestamps and user identity, satisfying SNARS accreditation requirements.

## User Stories

### SOAP Note Creation

1. As a **doctor**, I want to open a new SOAP note for a patient's active registration and fill in the Subjective, Objective, Assessment, and Plan sections, so that my clinical reasoning for the visit is formally documented.
2. As a **nurse**, I want to open a new SOAP note for a patient's active registration and fill in all four SOAP sections, so that my nursing assessment is formally documented alongside the doctor's note.
3. As a **doctor or nurse**, I want to record vital signs (temperature, systolic BP, diastolic BP, pulse rate, SpO2, respiratory rate, weight, height) as structured fields within the Objective section, so that vitals are captured in a queryable format rather than free text.
4. As a **doctor or nurse**, I want to save a SOAP note as `draft` before submitting, so that I can review and complete it before it becomes visible to the care team.
5. As a **doctor or nurse**, I want to edit any field of my own `draft` SOAP note before submitting, so that I can refine it before it is finalized.
6. As a **doctor or nurse**, I want to submit my SOAP note when it is complete, so that the note transitions to `submitted` and becomes visible to all treating staff.
7. As a **doctor or nurse**, I want the note timestamp (`noted_at`) to reflect the time of clinical encounter, not the time of submission, so that the note accurately represents when the assessment was made.

### Draft Visibility

8. As a **doctor or nurse**, I want my `draft` SOAP notes to be visible only to me, so that incomplete clinical notes do not mislead other treating staff.
9. As a **doctor**, I want to see all `submitted` SOAP notes on a patient's registration (written by any treating doctor or nurse), so that I have the full clinical picture before making my own assessment.
10. As a **nurse**, I want to see all `submitted` SOAP notes on a patient's registration, so that I can coordinate care with the rest of the team.

### Inpatient Daily Notes

11. As an **inpatient doctor**, I want to write a new SOAP note each day (or multiple per day after a procedure), so that the patient's daily progress is tracked throughout their admission.
12. As an **inpatient doctor**, I want to view all SOAP notes for a patient's admission in reverse-chronological order, so that the most recent clinical status is always at the top.

### Assessment & Diagnosis Recording

13. As a **doctor**, I want to select one or more ICD codes in the Assessment section of my SOAP note, so that the diagnoses are structured and linked to the visit.
14. As a **doctor**, I want to designate one diagnosis as primary and others as secondary or complication, so that the diagnostic hierarchy is clear.
15. As the **system**, I want diagnosis selections to be written to `hc_visit_diagnoses` via `HospitalCore\DiagnosisService` when the SOAP note is submitted, so that HospitalCore is the single source of truth for diagnoses.

### Prescription Integration

16. As a **doctor**, I want the Plan section of my SOAP note to include an inline prescription panel (`<PrescriptionPanel mode="prescribe" />`), so that I can create a prescription without leaving the note editor.
17. As the **system**, I want any prescription created from within the SOAP note editor to automatically have its `soap_note_id` set to the current note's ID, so that the prescription is traceable back to its clinical reasoning.
18. As a **doctor**, I want to view all prescriptions linked to a SOAP note directly from the note's Plan section, so that I can see what was ordered in context of the clinical assessment.
19. As a **doctor**, I want to create a prescription independently (without a SOAP note) in an emergency, so that urgent drug orders are never blocked by documentation requirements.

### Amendment

20. As a **doctor**, I want to formally amend a `submitted` SOAP note by creating a correction pre-filled with the original content, so that errors can be corrected without destroying the original record.
21. As a **nurse**, I want to formally amend my own `submitted` SOAP note, so that I can correct documentation errors after submission.
22. As the **system**, I want the original note to transition to `superseded` status when an amendment is submitted, so that the superseded version is clearly marked but still visible.
23. As a **doctor or nurse**, I want to see superseded notes in the note timeline with a clear "superseded" marker and a link to the amendment that replaced them, so that the full amendment chain is traceable.
24. As an **auditor**, I want every amendment to record which note it replaces (`amended_from_id`), who authored it, and when it was submitted, so that the SNARS-required audit trail is complete.
25. As a **doctor or nurse**, I want the amendment pre-filled with all four SOAP fields and vitals from the original note, so that I only need to correct the specific section that needs changing.

### Audit Trail

26. As an **auditor**, I want every SOAP note to record the author identity (`author_id`), author role (`author_role`), and submission timestamp, so that the clinical documentation trail is verifiable.
27. As an **auditor**, I want the full amendment chain for any note (original → amendments) to be reconstructable by following `amended_from_id` links, so that the complete revision history is available.
28. As a **medical records admin**, I want to view all SOAP notes for a patient across all visits from within the MedicalRecords module, so that the patient's longitudinal clinical record is accessible.

## Implementation Decisions

### Module Architecture

- **Module name**: `ClinicalDocumentation` (alias: `clinical-documentation`)
- **Dependencies**: `"require": ["hospital-core", "e-prescription"]` in `module.json`
- **Table prefix**: `cd_`
- **Consumers**: `MedicalRecords` reads `cd_soap_notes` read-only for the patient record viewer. `EPrescription` receives `soap_note_id` FK from this module.
- **Medical & Clinical Audit**:
    - **Access-as-Event (Log-on-Read)**: Every time a user opens a SOAP note for viewing, an access record is created in the audit log.
    - **Strict Addendum Policy**: Once `submitted`, notes are read-only. Any correction must follow the **Amendment Chain** workflow (creating a linked addendum).
    - **Break Glass**: Access to SOAP notes for patients not assigned to the user's current unit/poly requires a "Break Glass" override.
    - **Zero-Trust (MRS)**: If MRS is enabled, decryption of `cd_soap_notes.subjective/objective/assessment/plan` content is logged as a "Release" on the MRS.

### Schema: ClinicalDocumentation

| Table | Key Columns |
|---|---|
| `cd_soap_notes` | `id` (UUID), `registration_id` (FK → `hc_registrations`), `author_id` (FK → users), `author_role` (enum: `doctor`, `nurse`), `status` (enum: `draft`, `submitted`, `superseded`), `amended_from_id` (nullable self-FK → `cd_soap_notes` — amendment chain), `subjective` (text), `objective` (text), `assessment` (text), `plan` (text), `vitals` (JSON nullable), `noted_at` (timestamp), `submitted_at` (timestamp nullable), `created_by` (FK → users), timestamps, soft deletes |

### Vitals JSON Schema

The `vitals` column stores a fixed-key JSON object validated at the application layer by a `VitalsData` DTO. Allowed keys:

```
{
  "temperature":        decimal|null,  // °C
  "systolic_bp":        integer|null,  // mmHg
  "diastolic_bp":       integer|null,  // mmHg
  "pulse_rate":         integer|null,  // bpm
  "spo2":               decimal|null,  // %
  "respiratory_rate":   integer|null,  // breaths/min
  "weight":             decimal|null,  // kg
  "height":             decimal|null   // cm
}
```

No additional keys are permitted. Any unrecognized key throws `InvalidVitalsKeyException`. Null values are allowed for fields not measured.

### State Machine

```
draft → submitted → superseded
```

- `draft` → `submitted`: Author calls `SoapNoteService::submit(noteId, submittedBy)`. Validates at least one SOAP field is non-empty.
- `submitted` → `superseded`: Triggered automatically by `SoapNoteService::amend()` when the amendment note is submitted.
- No cancellation — notes are never deleted, only superseded. Soft deletes are reserved for admin correction only.

### Amendment Chain Design

`cd_soap_notes.amended_from_id` is a nullable self-referencing FK, mirroring the `epresc_prescriptions.source_prescription_id` pattern. This enables:
- Traversal of the full amendment chain (follow `amended_from_id` up to the root original note)
- `SoapNoteService::amend(sourceNoteId, registrationId, authorId)` creates a new `draft` note for the same registration, copies all SOAP fields and vitals from the source, sets `amended_from_id = sourceNoteId`
- When the amendment is submitted, the source note transitions to `superseded`

### EPrescription Schema Addition

`epresc_prescriptions` requires a new nullable column:
- `soap_note_id` (nullable UUID FK → `cd_soap_notes`) — links a prescription to the SOAP note whose Plan section produced it. Nullable to allow emergency prescriptions without a SOAP note.

### Service Layer

| Service | Responsibility |
|---|---|
| `SoapNoteService::create(registrationId, data, authorId, authorRole)` | Creates a new `draft` note. Validates registration is active. Validates vitals via `VitalsData` DTO. |
| `SoapNoteService::update(noteId, data, authorId)` | Updates fields on a `draft` note. Guards: note must be `draft`, caller must be the author. |
| `SoapNoteService::submit(noteId, submittedBy)` | Transitions `draft` → `submitted`. Calls `DiagnosisService::recordDiagnosis()` for each ICD code in the Assessment. Sets `submitted_at`. |
| `SoapNoteService::amend(sourceNoteId, authorId)` | Creates a new `draft` note with `amended_from_id = sourceNoteId`, copying all fields. Returns new note ID. Transitions source to `superseded` when the new note is submitted. |
| `SoapNoteQueryService::getForRegistration(registrationId, viewerId)` | Returns all `submitted` + `superseded` notes for the registration, plus the viewer's own `draft` notes. Excludes other authors' drafts. |
| `SoapNoteQueryService::getChain(noteId)` | Returns the full amendment chain (root original + all amendments) for a given note. |
| `SoapNoteQueryService::getForPatient(patientId)` | Returns all submitted notes across all registrations for a patient. Used by MedicalRecords. |

### Diagnosis Integration

When `SoapNoteService::submit()` is called, the assessment's ICD code selections (passed as part of the submission payload) are forwarded to `HospitalCore\DiagnosisService::recordDiagnosis()`. ClinicalDocumentation does not own a diagnosis table — it delegates entirely to HospitalCore. If `DiagnosisService` throws, the note submission is rolled back within the same DB transaction.

### Registration Type Constraints

Valid `hc_registrations.type` values for SOAP notes: `'er'`, `'inpatient'`, `'outpatient'`. `SoapNoteService::create()` throws `UnsupportedRegistrationTypeException` if the registration type is not in this set (e.g., `'direct-radiology'`, `'direct-lab'`).

### Frontend Architecture

- **SOAP Note Editor**: Tabbed sections (S / O / A / P) with a vitals structured form in the Objective tab and inline `<PrescriptionPanel mode="prescribe" />` at the bottom of the Plan tab. Auto-links `soap_note_id` on prescription creation.
- **Note Timeline**: Registration-scoped chronological list of submitted notes. Superseded notes rendered with a visual "Superseded" badge and a link to the amendment. Author role displayed on each note card (Doctor / Nurse).
- **Amendment Modal**: Opens pre-filled with the original note's content for editing before creating the amendment draft.
- **ICD Code Selector**: Searchable autocomplete in the Assessment tab. Supports primary / secondary / complication designation per code. Submits alongside the note.

### Tablet Optimization (Doctor & Nurse Mobility)

> [!IMPORTANT]
> The frontend UI for clinical documentation MUST be tablet-friendly. Doctors and nurses typically document at the bedside or while standing during rounds, where laptop usage is awkward.
> - **Touch targets**: UI elements (tabs, checkboxes, ICD selector) must be sized for touch input.
> - **Vitals Entry**: Ensure the vitals form is easy to fill on a tablet (numeric keypads, large input areas).
> - **Responsive layout**: Optimized for landscape and portrait tablet orientations.

### Permission Model

- `clinical-documentation.notes.view` — View submitted notes on registrations the user has access to
- `clinical-documentation.notes.create` — Create SOAP notes (doctors and nurses)
- `clinical-documentation.notes.amend` — Amend own submitted notes
- `clinical-documentation.notes.amend-any` — Amend any note (admin override)

## Testing Decisions

### What Makes a Good Test

- `SoapNoteService::submit()` — transitions `draft` → `submitted`; calls `DiagnosisService` with correct ICD payload; throws if all SOAP fields empty; rolls back if `DiagnosisService` throws
- `SoapNoteService::amend()` — new note has correct `amended_from_id`, all fields copied, status is `draft`; source transitions to `superseded` on amendment submit
- `SoapNoteQueryService::getForRegistration()` — returns submitted notes for all authors; returns caller's own draft; excludes other authors' drafts
- `VitalsData` DTO — throws `InvalidVitalsKeyException` for unknown keys; accepts null values for any field; rejects non-numeric values

### Test Structure

**Unit tests:**
- `SoapNoteService` — state machine transitions, diagnosis delegation guard (mock `DiagnosisService`), amendment chain creation
- `VitalsData` DTO — schema validation

**Feature tests:**
- `SoapNoteController` — create, update, submit, amend HTTP lifecycle
- Integration: submit note → `DiagnosisService` called → `hc_visit_diagnoses` row created
- Integration: create prescription from note editor → `soap_note_id` set on `epresc_prescriptions`
- `SoapNoteQueryService::getForRegistration()` — draft visibility filtering

### Prior Art

Follow test patterns from `Modules/Knowledgebase/tests/`. Use `RefreshDatabase`, model factories, `actingAs()`.

## Out of Scope

- **Nursing care plans / care pathways** — Structured nursing care plans (NCP) with goal, intervention, and evaluation columns. Deferred.
- **DAR format** — Data, Action, Response nursing note format. Out of scope for v1; both roles use SOAP.
- **Vital signs trend charts** — Graphical trending of vitals across notes/visits. Deferred to a future InpatientCare or MedicalRecords enhancement.
- **SOAP note templates** — Doctor-defined pre-configured templates for common presentations. Deferred.
- **Voice-to-text SOAP entry** — Dictation input. Out of scope.
- **Clinical Decision Support** — Drug interaction checks, contraindication alerts. Deferred to a future `ClinicalDecisionSupport` module.
- **Legacy data ETL** — No migration of historical paper-based SOAP notes.
- **Non-treating staff access** — Staff without an active role on the registration cannot view notes through this module.

## Further Notes

### Relationship to EPrescription and MedicalRecords

ClinicalDocumentation is the upstream clinical reasoning layer in a three-module chain:

```
ClinicalDocumentation → EPrescription → MedicalRecords
(Doctor/nurse writes    (Doctor orders    (Clerk files,
 SOAP note)              drugs from Plan)  reports, audits)
```

- EPrescription's `epresc_prescriptions.soap_note_id` FK (nullable) is the integration point between the two modules.
- MedicalRecords consumes `cd_soap_notes` read-only via `SoapNoteQueryService::getForPatient()` to display the patient's clinical history in the Patient Record Viewer.

### Amendment Chain vs. Refill Chain

`cd_soap_notes.amended_from_id` is conceptually parallel to `epresc_prescriptions.source_prescription_id`. Both implement a nullable self-referencing FK for an "originated from" chain. Neither creates the child automatically — the author must explicitly initiate an amendment (or a doctor must explicitly initiate a refill).

### SNARS Accreditation Alignment

Indonesian hospital accreditation (SNARS) requires that SOAP notes be documented per patient visit with a traceable amendment history. The `submitted` → `superseded` state transition with a preserved original record and an `amended_from_id` link directly satisfies this requirement. Soft deletes ensure no note is ever truly destroyed.
