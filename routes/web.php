<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Middleware\EnsureUserCanAccessDesignSystem;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::get('/', fn () => Inertia::render('Home'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/design-system', fn () => Inertia::render('DesignSystem'))
        ->middleware(EnsureUserCanAccessDesignSystem::class)
        ->name('design-system');

    Route::get('/tests', fn () => Inertia::render('Tests'))
        ->name('tests');

    Route::get('/push/config', [PushSubscriptionController::class, 'config'])->name('push.config');
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscriptions.store');
    Route::post('/push/test', [PushSubscriptionController::class, 'test'])->name('push.test');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');
    Route::post('/logout', LogoutController::class)->name('logout');
});
