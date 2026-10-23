<?php

use App\Http\Controllers\Api\MockInsurerController;
use App\Http\Controllers\Api\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes: PolicyFlow Engine
|--------------------------------------------------------------------------
*/

// Policy Request Intake, Retrieval, and Issuance
Route::prefix('policy-requests')->group(function () {
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit']);
});

// Mock Insurer & Callback Endpoints
Route::prefix('mock-insurer')->group(function () {
    Route::post('/submit', [MockInsurerController::class, 'submit']);
    Route::get('/status/{client_reference}', [MockInsurerController::class, 'status']);
    Route::post('/callback', [MockInsurerController::class, 'callback']);

    // Development only callback simulation trigger
    if (app()->environment('local', 'testing')) {
        Route::post('/dev/fire-callback/{provider_reference}', [MockInsurerController::class, 'fireCallback']);
    }
});
