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
    // Search ICD Codes API
    Route::get('/clinical-documentation/api/icd', [ClinicalDocumentationController::class, 'searchIcd'])
        ->name('clinicaldocumentation.api.icd');

    // Submit and finalize SOAP note
    Route::post('/clinical-documentation/{id}/submit', [ClinicalDocumentationController::class, 'submit'])
        ->name('clinicaldocumentation.submit');

    // Amend a finalized note
    Route::post('/clinical-documentation/{id}/amend', [ClinicalDocumentationController::class, 'amend'])
        ->name('clinicaldocumentation.amend');

    // Standard SOAP Note resource routes
    Route::resource('clinicaldocumentation', ClinicalDocumentationController::class)
        ->names('clinicaldocumentation');
});
