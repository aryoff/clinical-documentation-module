<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ClinicalDocumentation\Http\Controllers\ClinicalDisclosureController;
use Modules\ClinicalDocumentation\Http\Controllers\ClinicalDocumentationController;
use Modules\ClinicalDocumentation\Http\Controllers\ClinicalRecordDestructionController;
use Modules\ClinicalDocumentation\Http\Controllers\ClinicalRecordRetentionController;

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

Route::middleware(["web", "auth"])->prefix("clinical-documentation/retention")->name("clinicaldocumentation.retention.")->group(function () {
    Route::get("/", [ClinicalRecordRetentionController::class, "index"])->name("index");
    Route::get("/patients/{patient}", [ClinicalRecordRetentionController::class, "review"])->whereUuid("patient")->name("review");
    Route::post("/schedules", [ClinicalRecordRetentionController::class, "storeSchedule"])->name("schedules.store");
    Route::post("/holds", [ClinicalRecordRetentionController::class, "placeHold"])->name("holds.store");
    Route::post("/holds/{hold}/lift", [ClinicalRecordRetentionController::class, "liftHold"])->whereUuid("hold")->name("holds.lift");
    Route::post("/extensions", [ClinicalRecordRetentionController::class, "storeExtension"])->name("extensions.store");

    Route::get("/destruction", [ClinicalRecordDestructionController::class, "index"])->name("destruction.index");
    Route::post("/patients/{patient}/destroy", [ClinicalRecordDestructionController::class, "destroy"])->whereUuid("patient")->name("destroy");
    Route::post("/destruction/{destruction}/resume", [ClinicalRecordDestructionController::class, "resume"])->whereUuid("destruction")->name("destruction.resume");
});

Route::middleware(["web", "auth"])->prefix("clinical-documentation/disclosure")->name("clinicaldocumentation.disclosure.")->group(function () {
    Route::post("/requests", [ClinicalDisclosureController::class, "storeRequest"])->name("requests.store");
    Route::post("/requests/{disclosureRequest}/verification", [ClinicalDisclosureController::class, "storeVerification"])->whereUuid("disclosureRequest")->name("verifications.store");
    Route::post("/consents", [ClinicalDisclosureController::class, "storeConsent"])->name("consents.store");
    Route::post("/consents/{consent}/withdraw", [ClinicalDisclosureController::class, "withdrawConsent"])->whereUuid("consent")->name("consents.withdraw");
});

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

    // Standard Clinical Document routes.
    Route::resource(
        "clinicaldocumentation",
        ClinicalDocumentationController::class,
    )->names("clinicaldocumentation");
});
