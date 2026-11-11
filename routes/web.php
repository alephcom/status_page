<?php

use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TelegramController;
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

Route::post('/subscribe/google', [GoogleAuthController::class, 'subscribe'])->middleware('throttle:10,1')->name('subscribe.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1')->name('auth.google.callback');
Route::get('/auth/google/{intent}', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1')->name('auth.google');

Route::post('/subscribe/telegram', [TelegramController::class, 'subscribe'])->middleware('throttle:10,1')->name('subscribe.telegram');
Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])->name('telegram.webhook');
