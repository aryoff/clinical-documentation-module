<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
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

Route::middleware(['web', 'auth'])->group(function () {
    Route::middleware('capability:hospitalcore.hospital-registration')->group(function () {
        Route::get('/clinical-documentation/presented-external-evidence/create', [ClinicalDocumentationController::class, 'createPresentedExternalEvidence'])
            ->name('clinicaldocumentation.presented-external-evidence.create');

        Route::post('/clinical-documentation/presented-external-evidence', [ClinicalDocumentationController::class, 'storePresentedExternalEvidence'])
            ->name('clinicaldocumentation.presented-external-evidence.store');

        Route::post('/clinical-documentation/presented-external-evidence/{id}/review', [ClinicalDocumentationController::class, 'reviewPresentedExternalEvidence'])
            ->name('clinicaldocumentation.presented-external-evidence.review');

        Route::post('/clinical-documentation/presented-external-evidence/{id}/incorporate', [ClinicalDocumentationController::class, 'incorporatePresentedExternalEvidence'])
            ->name('clinicaldocumentation.presented-external-evidence.incorporate');

        Route::get('/clinical-documentation/presented-external-evidence/{id}/file', [ClinicalDocumentationController::class, 'filePresentedExternalEvidence'])
            ->name('clinicaldocumentation.presented-external-evidence.file');
    });

    // Sign an immutable Clinical Document.
    Route::post('/clinical-documentation/{id}/submit', [ClinicalDocumentationController::class, 'submit'])
        ->name('clinicaldocumentation.submit');

    // Create and sign a reasoned addendum without changing the source document.
    Route::post('/clinical-documentation/{id}/amend', [ClinicalDocumentationController::class, 'amend'])
        ->name('clinicaldocumentation.amend');

    // Reasoned emergency access. It buys one audited read of a signed document
    // and never a write, so it is deliberately its own path rather than a
    // relaxation of the treating-access rule guarding `show`.
    Route::get('/clinical-documentation/{id}/break-glass', [ClinicalDocumentationController::class, 'breakGlassForm'])
        ->name('clinicaldocumentation.break-glass.create');

    Route::post('/clinical-documentation/{id}/break-glass', [ClinicalDocumentationController::class, 'breakGlass'])
        ->name('clinicaldocumentation.break-glass');

    // Authorize a bounded archive package for one signed document. Gated by
    // RequestClinicalArchiveRequest::authorize() so the Show page can offer the
    // action only to a user who holds custody.
    Route::post('/clinical-documentation/{id}/archive', [ClinicalDocumentationController::class, 'archive'])
        ->name('clinicaldocumentation.archive');

    // Access-as-Event evidence, including every Break-Glass awaiting review.
    // Gated by ViewClinicalAuditRequest::authorize() rather than middleware so
    // the sidebar entry disappears for a user who cannot open it.
    Route::get('/clinical-documentation/audit', [ClinicalDocumentationController::class, 'audit'])
        ->name('clinicaldocumentation.audit');

    // Standard Clinical Document routes.
    Route::resource('clinicaldocumentation', ClinicalDocumentationController::class)
        ->names('clinicaldocumentation');
});
