<?php

use Illuminate\Support\Facades\Route;
use Modules\ClinicalDocumentation\Http\Controllers\ClinicalDocumentationController;

/*
 *--------------------------------------------------------------------------
 * API Routes
 *--------------------------------------------------------------------------
 *
 * The generated destroy route was not a clinical-document API. It only
 * reached the controller's deliberate 405 response and carried no
 * permission/FormRequest boundary. The remaining shared actions are kept
 * until the middleware-only routes are converted in #211.
 *
 */

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('clinicaldocumentation', ClinicalDocumentationController::class)
        ->middlewareFor(['index', 'show'], 'permission:clinicaldocumentation.records.read')
        ->middlewareFor(['store', 'update'], 'permission:clinicaldocumentation.documents.author')
        ->except(['destroy'])
        ->names('clinicaldocumentation');
});
