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
- **Permissions**: Defined in `module.json` and synced via `ModulePermissionsSyncSeeder`. UI visibility is controlled by these strings matching the `routeName` or used in `FormRequest::authorize()`.
- **Menu Structure**: Defined in `module.json`. Filtered authoritatively by `PopulateModuleMenuAndZiggyController`.
- **Icon Mapping**: `module.json` `"icon"` must be an exact PascalCase `lucide-react` export name present in the `menuIconComponents` allowlist in `resources/js/Assets/MenuIcons.tsx`. Do not add custom SVG wrappers for ordinary module menu icons (root ADR 0001).

## Testing
- **Backend Tests**: `./vendor/bin/sail artisan test --parallel --processes=8 Modules/ClinicalDocumentation/tests`
- **Frontend Tests**: `npx vitest run Modules/ClinicalDocumentation/resources/assets/js`

