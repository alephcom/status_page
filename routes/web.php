<?php

use App\Http\Controllers\StatusController;
use App\Http\Controllers\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [StatusController::class, 'index'])->name('status');
Route::get('/history', [StatusController::class, 'history'])->name('history');
Route::get('/incidents/{incident}', [StatusController::class, 'incident'])->name('incidents.show');
Route::get('/feed.atom', [StatusController::class, 'feed'])->name('feed');

Route::get('/subscribe', [SubscriptionController::class, 'create'])->name('subscribe');
Route::post('/subscribe', [SubscriptionController::class, 'store'])->middleware('throttle:5,1')->name('subscribe.store');
Route::get('/subscription/{subscriber}', [SubscriptionController::class, 'show'])->name('subscription.show');
Route::put('/subscription/{subscriber}', [SubscriptionController::class, 'update'])->name('subscription.update');
Route::delete('/subscription/{subscriber}', [SubscriptionController::class, 'destroy'])->name('subscription.destroy');
Route::get('/subscription/{subscriber}/verify', [SubscriptionController::class, 'verify'])->name('subscription.verify');
