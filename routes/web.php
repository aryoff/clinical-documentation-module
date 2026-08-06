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
    // Sign an immutable Clinical Document.
    Route::post('/clinical-documentation/{id}/submit', [ClinicalDocumentationController::class, 'submit'])
        ->middleware('permission:clinicaldocumentation.documents.sign')
        ->name('clinicaldocumentation.submit');

    // Create and sign a reasoned addendum without changing the source document.
    Route::post('/clinical-documentation/{id}/amend', [ClinicalDocumentationController::class, 'amend'])
        ->middleware('permission:clinicaldocumentation.documents.amend')
        ->name('clinicaldocumentation.amend');

    // Standard Clinical Document routes.
    Route::resource('clinicaldocumentation', ClinicalDocumentationController::class)
        ->middlewareFor(['index', 'show'], 'permission:clinicaldocumentation.records.read')
        ->middlewareFor(['create', 'store', 'edit', 'update'], 'permission:clinicaldocumentation.documents.author')
        ->names('clinicaldocumentation');
});
