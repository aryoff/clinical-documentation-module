# ClinicalDocumentation Module AGENTS.md (`Modules/ClinicalDocumentation/`)

This module handles Electronic Medical Records (EMR) and clinical documentation for healthcare providers.

## Package Identity
- **Purpose**: Digitizing clinical workflows and patient assessments.
- **Primary Tech**: Laravel, React, TypeScript.

## Frontend Patterns & Conventions
- **Layout**: Uses `AuthenticatedLayout` from `@/Layouts/AuthenticatedLayout/AuthenticatedLayout`.
- **Navigation/Actions**: Uses `Content` with the `actionBar` prop for module-specific sidebars or toolbars.
- **Action Bar Width**: Uses `useActionBar().setExpandedWidth(width)` (often via a `TabWidthSetter` component) to manage sidebar persistent state.
- **Styling**: Always use root's `global.css` for consistent design tokens and layout variables.

## Clinical Authority Gating

Three of this module's permissions are classified **clinical** in HospitalCore's `module.json` — `documents.author`, `documents.sign` and `documents.amend`. Reading, auditing, archiving and Break-Glass are not. A clinical permission is refused by `Gate::before` unless the user holds a matching Clinical Authority, so holding it by role is **not** proof the action is available.

- **Gate in a `FormRequest`; `permission:` middleware does not satisfy this rule.** `/populateSidebar` reflects the `FormRequest` and strips the route when `authorize()` refuses, but it cannot see middleware. The module's routes therefore decide in their requests, with the canonical rule in [Modules/AGENTS.md](../AGENTS.md#authorization-belongs-in-a-formrequest).
- **Guard every control with `route().has()`.** The three authorities are distinct and pages are reachable without them: `Pages/Edit.tsx` shows "Sign and lock" only where the signing route survived, `Pages/Show.tsx` fits the addendum panel only where the amend route did, and `Pages/Index.tsx` links a draft to its editor only where authoring did — `route()` on a name Ziggy no longer carries throws rather than returning a dead href. See [Modules/AGENTS.md](../AGENTS.md#and-the-control-it-gates-is-not-rendered).
- **A domain refusal is not an authorization refusal.** Editing a signed document, submitting twice, or emptying a draft must be answered by the domain rule, with a credentialed actor. Tests that credential through `tests/Support/CredentialsClinicalActors` prove the rule; an uncredentialed actor proves only the Gate.

## Modular Classification
- **Classification**: **OPTIONAL** module.
- **Role**: Part of the "Hospital Layer". Owns the Active Clinical Record — signed clinical documents, Diagnosis Assertions, Allergy Assertions, addenda, and the authorization of encrypted archive release.
- **Boundary**: See [CONTEXT.md](CONTEXT.md) for the shared language and [ADR 0001](docs/adr/0001-sign-clinical-documents-and-correct-them-by-addendum.md) for the ownership decision.

## Ownership Boundaries
- **Authoring is handoff-gated**: Only an explicit accepted Clinical Handoff from ER, outpatient, or inpatient care permits authoring. A queue position, bed allocation, or ward roster is not authorization.
- **Diagnoses and allergies are owned here**: Diagnosis Assertions and Allergy Assertions are this module's clinical facts. HospitalCore supplies only the ICD vocabulary; it is not the diagnosis record owner. `hc_visit_diagnoses` and `HospitalCore\DiagnosisService` were retired by #255 and must not come back.
- **Only this module asserts**: Laboratory and Radiology publish Diagnostic Result Evidence through `recordDiagnosticResultEvidence()` and cite nothing on a clinician's behalf. No other module may reach `DiagnosisAssertion` or `assertDiagnosis`; an architecture test enforces it.
- **EPrescription is independent**: No embedded `PrescriptionPanel`, no `soap_note_id` foreign key. Link prescriptions through the public Clinical Rationale Reference; urgent prescribing must work with no clinical document.
- **MedicalRecords is optional**: It is a ciphertext-only vault. This module is the sole key holder, encrypts before upload, holds the archive manifest, and authorizes each bounded one-patient release. Archive unavailability must never block discharge.
- **No billing from signing**: Signing a document, diagnosis, or allergy never creates a Billable Service Fact. Ancillary providers own their own orders, results, and charges.

## Administration fact projection

- Consume `eprescription.administration-clinical-fact` through its named scalar event; never import source classes or write source storage. Receipt creates neither a Clinical Handoff nor authoring authority.
- `AdministrationProjection` and `ArchiveAdministrationEntry` are insert-only evidence. They deliberately omit soft deletes; model guards and database triggers refuse update/delete. Corrections and basis reconciliation append source-linked facts.
- Read frozen patient IDs through `ClinicalPatientIdentity`; never refile these point-in-time snapshots during identity reconciliation.
- The chronological read includes signed documents/addenda and source facts, requires treating access, and logs its purpose. Its HTTP request also requires `records.read`.
- An existing author-authorized `archiveDocument` request references the registration's administration projections. Repeating that intent can include late facts. Receipt alone requests no archive. Encryption, upload, verified custody and bounded release remain root #56; this change creates no local encryption or MedicalRecords client.

## Clinical Record Retention (reporting stage)

Root ADR 0033 / #473. This module owns the retention policy, the legal-hold register and the review; HospitalCore owns Patient
Last Visit, and custodians (Laboratory and others) execute destruction steps when asked.

- **Schedules** (`cd_retention_schedules`): versioned, 25-year floor, a version may lengthen protection and never shorten it. Insert-only.
  Every review cites the schedule version it applied.
- **Holds** (`cd_legal_holds`, history in `cd_legal_hold_events`): the one authoritative register. A hold is lifted once and never edited or
  removed; time never ends one; overlapping holds are independent. `ReassignReconciledPatient` refiles holds and extensions onto the
  survivor, so a merge never loses protection.
- **Extensions** (`cd_retention_extensions`): continuing usefulness, an explicit attributable decision with a reason and a future date.
- **Review** (`ClinicalRecordRetentionReview`): eligible only when no blocker exists. Missing evidence (no schedule, no last-visit
  provider, incomplete attendance coverage, unknown last visit) is a blocker, never a pass. Reaching a retention date permits review only.
- **Destruction** (`ClinicalRecordDestruction`, clinical-documentation-module#8, decision record root #488): the mandatory duty is to keep records
  at least 25 years from the last visit (Permenkes 24/2022 Pasal 39(1); PP 28/2024 Pasal 974(3)); destruction afterwards is optional. It is **one
  authorized action** (`retention.destroy`) that acknowledges no retained clinical record still needs the content, covers every copy across
  custodians (Laboratory by capability ID, plus this module's own Diagnostic Result Evidence found by `result_reference_id`) and runs them one by one.
  Every custodian asks `clinicaldocumentation.destruction-authorization` before each irreversible step; `problem()` re-checks the review, the
  dependencies and discovered copies under a per-patient lock that hold and extension writes also take. A hold, a later visit or a discovered copy
  stops the rest; what was destroyed stays destroyed and the rest can be resumed.
- **Processing history** (PP 28/2024 Pasal 977): `cd_destructions` (who, why, when) and `cd_destruction_items` (each copy and its receipt), never the
  findings. A step stops at the first failure, leaves a truthful `partial`, and retries safely because custodians answer a repeat with the receipt
  they already issued. `clinicaldocumentation:resume-destruction` finishes a started destruction and can never start one.
- **Off by default**: `clinicaldocumentation.retention.destruction_enabled`. There is no approval step, backup state or restore tooling: backups are the
  facility's own duty (Permenkes Pasal 20(4)-(5)) and a restore is disaster recovery, where resurrected past-retention data is simply destroyed again.
- Evidence still cited by a retained assertion is protected (no cascade), and a patient with an archived copy (`cd_archive_packages`) is blocked
  until the vault's custody can be proven (#56).
- **Abilities** `clinicaldocumentation.retention.{view,schedule,hold,extend,destroy}` are checked in plain FormRequests. They are not clinical
  authorities, and the ordinary record abilities confer none of them.
- **Last visit by capability ID**: `PatientLastVisitPort` forwards to `hospitalcore.patient-last-visit` (optional); absence reads as
  `last_visit_unavailable`.

## Clinical Audit Standards
- **Access-as-Event (Log-on-Read)**: Every "Read" operation on a clinical document must be logged.
- **Addendum-Only Immutability**: Once a clinical document is signed, it is immutable. Corrections must be created as linked `ClinicalAddendum` records that preserve the original. A *document* has no `superseded` state.
- **Diagnoses correct by lineage, not by edit**: a Diagnosis Assertion is immutable too, but it belongs to a lineage. A `supersession` appends a successor at the next revision of its predecessor's `lineage_id`; a `supplement` opens a parallel lineage. Head status is derived from what nothing supersedes — never store an `is_current` or `superseded_at` column, and never `update()` an assertion. See [ADR 0001](docs/adr/0001-sign-clinical-documents-and-correct-them-by-addendum.md#amended-a-diagnosis-assertion-carries-a-lineage).
- **Template Versioning**: A signed record stays bound to the Clinical Document Template version it was signed against.
- **Break Glass Protocol**: Unauthorized clinical access in emergencies requires a "Break Glass" action with a mandatory reason, flagged for security review. It grants neither authoring nor ongoing access.
- **Zero-Trust Synchronization**: The Application logs the **Intent** (Request) while the vault logs the **Release**. Use a `CorrelationID` to link them.

## Global Consistency (DRY)
- **Shared Resources**: Before creating new components or utilities, check the root `resources/js/` directory (`Components`, `Hooks`, `Utils`, `Layouts`).
- **Standard Layout**: Reference the root [AGENTS.md](file:///home/aryoff/Dev/boilerplate-laravel-react/AGENTS.md) for universal project conventions.
- **Module Base**: See [Modules/AGENTS.md](file:///home/aryoff/Dev/boilerplate-laravel-react/Modules/AGENTS.md) for general modular patterns.

## Touch Points / Key Files
- **Inertia Pages**: `resources/assets/js/Pages/`
- **Module Components**: `resources/assets/js/Components/`
- **Backend Logic**: `app/Http/Controllers/`

## JIT Index Hints
- **Find pages**: `find resources/assets/js/Pages -name "*.tsx"`
- **Find controllers**: `find app/Http/Controllers -name "*Controller.php"`

## System Integration (module.json)
This module makes extensive use of `module.json` for system integration:
- **`require` is empty, deliberately**: nothing in this module's application code imports HospitalCore. The ICD-10 vocabulary is validated against a code shape here, not fetched from `HospitalCore\DiagnosisService`, so there is no collaboration to declare. If a real dependency appears, declare it as a capability consumption in `capabilities.consumes` — not as a module `require`.
- **Permissions**: Defined in `module.json` and synced via `ModulePermissionsSyncSeeder`. UI visibility is controlled by these strings matching the `routeName` or used in `FormRequest::authorize()`.
- **Menu Structure**: Defined in `module.json`. Filtered authoritatively by `PopulateModuleMenuAndZiggyController`.
- **Icon Mapping**: `module.json` `"icon"` must be an exact PascalCase `lucide-react` export name present in the `menuIconComponents` allowlist in `resources/js/Assets/MenuIcons.tsx`. Do not add custom SVG wrappers for ordinary module menu icons (root ADR 0001).

## Testing
- **Backend Tests**: `./vendor/bin/sail artisan test --parallel --processes=8 Modules/ClinicalDocumentation/tests --exclude-group=browser` — the `--exclude-group` matters. `phpunit.xml` excludes `Modules/*/tests/Browser` from the `Modules` suite, but naming a path on the command line bypasses that and would run the browser journey without a browser harness.
- **Frontend Tests**: `npx vitest run Modules/ClinicalDocumentation/resources/assets/js`
- **Browser Journey**: `./vendor/bin/sail vendor/bin/pest --configuration=phpunit.dusk.xml --group=clinical-authoring` — select by group, not by path; a path argument matches nothing against that configuration's suites.
- **Permission coverage**: every string in `module.json` `permissions` is exercised at the HTTP boundary in both outcomes by `tests/Feature/ClinicalDocumentationAuthorizationTest.php`. Adding a permission means adding its authorized and its denied test there.
- **Authoring needs a handoff**: no care context accepts a Clinical Handoff through its own UI yet, so tests accept one through `ActiveClinicalRecordContract::acceptHandoff()` before driving the authoring routes.

## Emergency Access (Break Glass)
- **Route**: `POST /clinical-documentation/{id}/break-glass`, with `GET` serving the reason form. Both consume `clinicaldocumentation.records.break-glass`.
- **Reachability**: `show` redirects a break-glass holder to the reason form when they have no treating handoff, so the emergency path is reached the way a responder actually meets it.
- **Scope**: signed documents only — a private draft stays private to its author. The access records actor, reason, time and a correlation id, is flagged `security_review_required`, and grants no write.
