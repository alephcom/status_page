<?php

use App\Http\Controllers\Api\ManageApiController;
use App\Http\Controllers\Api\StatusApiController;
use App\Http\Middleware\EnsureStaff;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/status', [StatusApiController::class, 'status']);
    Route::get('/components', [StatusApiController::class, 'components']);
    Route::get('/incidents', [StatusApiController::class, 'incidents']);
    Route::get('/incidents/{incident}', [StatusApiController::class, 'incident']);
    Route::get('/maintenances', [StatusApiController::class, 'maintenances']);

    // Write API: bearer token of an admin or editor (Settings → API tokens).
    Route::middleware(['auth:sanctum', EnsureStaff::class])->group(function () {
        Route::post('/incidents', [ManageApiController::class, 'createIncident']);
        Route::patch('/incidents/{incident}', [ManageApiController::class, 'updateIncident']);
        Route::post('/incidents/{incident}/updates', [ManageApiController::class, 'postUpdate']);
        Route::post('/maintenances', [ManageApiController::class, 'createMaintenance']);
        Route::patch('/maintenances/{maintenance}', [ManageApiController::class, 'updateMaintenance']);
        Route::delete('/maintenances/{maintenance}', [ManageApiController::class, 'deleteMaintenance']);
        Route::put('/components/{component}/override', [ManageApiController::class, 'setOverride']);
    });
});
