<?php

use App\Http\Controllers\Api\StatusApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:60,1')->group(function () {
    Route::get('/status', [StatusApiController::class, 'status']);
    Route::get('/components', [StatusApiController::class, 'components']);
    Route::get('/incidents', [StatusApiController::class, 'incidents']);
    Route::get('/incidents/{incident}', [StatusApiController::class, 'incident']);
    Route::get('/maintenances', [StatusApiController::class, 'maintenances']);
});
