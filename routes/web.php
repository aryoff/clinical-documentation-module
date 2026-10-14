<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ClinicalDocumentation\Http\Controllers\ClinicalConsumableController;
use Modules\ClinicalDocumentation\Http\Controllers\ClinicalDocumentationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::middleware(["web", "auth"])->group(function () {
    Route::get("/clinical-documentation/patients/{patient}/record", [
        ClinicalDocumentationController::class,
        "record",
    ])
        ->whereUuid("patient")
        ->name("clinicaldocumentation.records.show");

    Route::middleware("capability:hospitalcore.hospital-registration")->group(
        function () {
            Route::get(
                "/clinical-documentation/presented-external-evidence/create",
                [
                    ClinicalDocumentationController::class,
                    "createPresentedExternalEvidence",
                ],
            )->name("clinicaldocumentation.presented-external-evidence.create");

            Route::post("/clinical-documentation/presented-external-evidence", [
                ClinicalDocumentationController::class,
                "storePresentedExternalEvidence",
            ])->name("clinicaldocumentation.presented-external-evidence.store");

            Route::post(
                "/clinical-documentation/presented-external-evidence/{id}/review",
                [
                    ClinicalDocumentationController::class,
                    "reviewPresentedExternalEvidence",
                ],
            )->name("clinicaldocumentation.presented-external-evidence.review");

            Route::post(
                "/clinical-documentation/presented-external-evidence/{id}/incorporate",
                [
                    ClinicalDocumentationController::class,
                    "incorporatePresentedExternalEvidence",
                ],
            )->name(
                "clinicaldocumentation.presented-external-evidence.incorporate",
            );

            Route::get(
                "/clinical-documentation/presented-external-evidence/{id}/file",
                [
                    ClinicalDocumentationController::class,
                    "filePresentedExternalEvidence",
                ],
            )->name("clinicaldocumentation.presented-external-evidence.file");
        },
    );

    // Sign an immutable Clinical Document.
    Route::post("/clinical-documentation/{id}/submit", [
        ClinicalDocumentationController::class,
        "submit",
    ])->name("clinicaldocumentation.submit");

    // Create and sign a reasoned addendum without changing the source document.
    Route::post("/clinical-documentation/{id}/amend", [
        ClinicalDocumentationController::class,
        "amend",
    ])->name("clinicaldocumentation.amend");

    // Reasoned emergency access. It buys one audited read of a signed document
    // and never a write, so it is deliberately its own path rather than a
    // relaxation of the treating-access rule guarding `show`.
    Route::get("/clinical-documentation/{id}/break-glass", [
        ClinicalDocumentationController::class,
        "breakGlassForm",
    ])->name("clinicaldocumentation.break-glass.create");

    Route::post("/clinical-documentation/{id}/break-glass", [
        ClinicalDocumentationController::class,
        "breakGlass",
    ])->name("clinicaldocumentation.break-glass");

    // Authorize a bounded archive package for one signed document. Gated by
    // RequestClinicalArchiveRequest::authorize() so the Show page can offer the
    // action only to a user who holds custody.
    Route::post("/clinical-documentation/{id}/archive", [
        ClinicalDocumentationController::class,
        "archive",
    ])->name("clinicaldocumentation.archive");

    // Name what the signed document concluded. Appended to the patient's
    // diagnosis lineage; never an edit of an existing assertion. Gated by
    // AssertDiagnosisRequest::authorize() so the Show page's form disappears
    // for an account that cannot author.
    Route::post("/clinical-documentation/{id}/diagnoses", [
        ClinicalDocumentationController::class,
        "assertDiagnosis",
    ])->name("clinicaldocumentation.diagnoses.assert");

    // The Clinical Diagnosis Read: what was concluded and when it changed,
    // without the signed notes behind it. Its own route because it is patient-
    // scoped rather than document-scoped, and grants no document access.
    Route::get("/clinical-documentation/patients/{patient}/diagnoses", [
        ClinicalDocumentationController::class,
        "diagnoses",
    ])->name("clinicaldocumentation.diagnoses.index");

    // Access-as-Event evidence, including every Break-Glass awaiting review.
    // Gated by ViewClinicalAuditRequest::authorize() rather than middleware so
    // the sidebar entry disappears for a user who cannot open it.
    Route::get("/clinical-documentation/audit", [
        ClinicalDocumentationController::class,
        "audit",
    ])->name("clinicaldocumentation.audit");

    // Medical-consumable use. Unavailable (404) wherever no custodian is enabled, and each
    // action decides in its FormRequest so the sidebar publishes only what the user may do.
    Route::middleware("capability:pharmacy.consumable-stock")->group(
        function () {
            Route::post("/clinical-documentation/consumables/orders", [
                ClinicalConsumableController::class,
                "order",
            ])->name("clinicaldocumentation.consumables.order");
            Route::post("/clinical-documentation/consumables", [
                ClinicalConsumableController::class,
                "store",
            ])->name("clinicaldocumentation.consumables.store");
            Route::post("/clinical-documentation/consumables/{use}/correct", [
                ClinicalConsumableController::class,
                "correct",
            ])
                ->whereUuid("use")
                ->name("clinicaldocumentation.consumables.correct");
            Route::post("/clinical-documentation/consumables/{use}/waste", [
                ClinicalConsumableController::class,
                "waste",
            ])
                ->whereUuid("use")
                ->name("clinicaldocumentation.consumables.waste");
        },
    );

    // Standard Clinical Document routes.
    Route::resource(
        "clinicaldocumentation",
        ClinicalDocumentationController::class,
    )->names("clinicaldocumentation");
});
