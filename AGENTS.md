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
- **Role**: Part of the "Hospital Layer". Provides specialized electronic medical record (EMR) forms for clinical practitioners.

## Clinical Audit Standards
- **Access-as-Event (Log-on-Read)**: Every "Read" operation on a SOAP note must be logged.
- **Addendum-Only Immutability**: Once a clinical document is "Signed/Finalized," it is read-only. Corrections must be created as linked `ClinicalAddendum` records.
- **Break Glass Protocol**: Unauthorized clinical access in emergencies requires a "Break Glass" action with a mandatory reason, flagged for security review.
- **Zero-Trust Synchronization**: When using an encrypted MRS, the Application logs the **Intent** (Request) while the MRS logs the **Release** (Decryption). Use a `CorrelationID` to link them.

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
- **Icon Mapping**: Uses specific icons (e.g., `IconClinicalDocumentation`) mapped in `resources/js/Assets/SvgIcons.tsx`.

## Testing
- **Backend Tests**: `./vendor/bin/sail artisan test --parallel --processes=8 Modules/ClinicalDocumentation/tests`
- **Frontend Tests**: `npx vitest run Modules/ClinicalDocumentation/resources/assets/js`

