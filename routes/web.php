<?php

use App\Http\Controllers\AccountAuthController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\StatusController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TelegramController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::middleware(SetLocale::class)->group(function () {
    Route::get('/', [StatusController::class, 'index'])->name('status');
    Route::get('/history', [StatusController::class, 'history'])->name('history');
    Route::get('/incidents/{incident}', [StatusController::class, 'incident'])->name('incidents.show');
    Route::get('/components/{component}/days/{date}', [StatusController::class, 'componentDay'])->where('date', '\d{4}-\d{2}-\d{2}')->name('components.day');
    Route::get('/feed.atom', [StatusController::class, 'feed'])->name('feed');
    Route::get('/maintenance.ics', [StatusController::class, 'calendar'])->name('calendar');
    Route::get('/badge.svg', [StatusController::class, 'badge'])->name('badge');
    Route::get('/badge/{component}.svg', [StatusController::class, 'componentBadge'])->name('badge.component');
    Route::get('/embed.js', [StatusController::class, 'embed'])->name('embed');

    Route::get('/subscribe', [SubscriptionController::class, 'create'])->name('subscribe');
    Route::post('/subscribe', [SubscriptionController::class, 'store'])->middleware('throttle:5,1')->name('subscribe.store');
    Route::get('/subscription/{subscriber}', [SubscriptionController::class, 'show'])->name('subscription.show');
    Route::put('/subscription/{subscriber}', [SubscriptionController::class, 'update'])->name('subscription.update');
    Route::delete('/subscription/{subscriber}', [SubscriptionController::class, 'destroy'])->name('subscription.destroy');
    Route::get('/subscription/{subscriber}/verify', [SubscriptionController::class, 'verify'])->name('subscription.verify');

    // Public accounts: sign in to manage your own subscription.
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AccountAuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AccountAuthController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
        Route::get('/register', [AccountAuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AccountAuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
        Route::get('/forgot-password', [AccountAuthController::class, 'showForgot'])->name('password.request');
        Route::post('/forgot-password', [AccountAuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
        Route::get('/reset-password/{token}', [AccountAuthController::class, 'showReset'])->name('password.reset');
        Route::post('/reset-password', [AccountAuthController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
    });

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AccountAuthController::class, 'logout'])->name('logout');
        Route::get('/account', [AccountController::class, 'show'])->name('account');
        Route::put('/account/subscription', [AccountController::class, 'updateSubscription'])->name('account.subscription.update');
        Route::delete('/account/subscription', [AccountController::class, 'destroySubscription'])->name('account.subscription.destroy');
        Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
        Route::get('/account/verify/{id}/{hash}', [AccountAuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
        Route::post('/account/verify', [AccountAuthController::class, 'resendVerification'])->middleware('throttle:3,1')->name('verification.send');
    });
});

Route::get('/health', HealthController::class)->name('health');

Route::post('/subscribe/google', [GoogleAuthController::class, 'subscribe'])->middleware('throttle:10,1')->name('subscribe.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1')->name('auth.google.callback');
Route::get('/auth/google/{intent}', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1')->name('auth.google');

Route::post('/subscribe/telegram', [TelegramController::class, 'subscribe'])->middleware('throttle:10,1')->name('subscribe.telegram');
Route::post('/telegram/webhook', [TelegramController::class, 'webhook'])->name('telegram.webhook');
