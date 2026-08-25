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
- **Diagnoses and allergies are owned here**: Diagnosis Assertions and Allergy Assertions are this module's clinical facts. HospitalCore supplies only the ICD vocabulary; it is not the diagnosis record owner. Do not write `hc_visit_diagnoses` through `HospitalCore\DiagnosisService`.
- **EPrescription is independent**: No embedded `PrescriptionPanel`, no `soap_note_id` foreign key. Link prescriptions through the public Clinical Rationale Reference; urgent prescribing must work with no clinical document.
- **MedicalRecords is optional**: It is a ciphertext-only vault. This module is the sole key holder, encrypts before upload, holds the archive manifest, and authorizes each bounded one-patient release. Archive unavailability must never block discharge.
- **No billing from signing**: Signing a document, diagnosis, or allergy never creates a Billable Service Fact. Ancillary providers own their own orders, results, and charges.

## Clinical Audit Standards
- **Access-as-Event (Log-on-Read)**: Every "Read" operation on a clinical document must be logged.
- **Addendum-Only Immutability**: Once a clinical document is signed, it is immutable. Corrections must be created as linked `ClinicalAddendum` records that preserve the original. There is no `superseded` state.
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
