<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Admin\TelegramBindingController as AdminTelegramBindingController;
use App\Http\Controllers\Admin\TelegramReaderController as AdminTelegramReaderController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\CioPresentationController;
use App\Http\Controllers\KommersantRankingController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\VacancySourceController;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::get('/', fn () => Inertia::render('Home'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/projects', fn () => Inertia::render('Projects'))->name('projects');
    Route::get('/projects/bmp-to-mip', fn () => Inertia::render('BmpToMip'))->name('projects.bmp-to-mip');
    Route::get('/projects/pushkin-fairytales', fn () => Inertia::render('PushkinFairytales'))->name('projects.pushkin-fairytales');
    Route::get('/projects/reading-diary', fn () => Inertia::render('ReadingDiary'))->name('projects.reading-diary');
    Route::get('/projects/cio-presentations', [CioPresentationController::class, 'index'])
        ->name('projects.cio-presentations');
    Route::get('/projects/kommersant-ranking', [KommersantRankingController::class, 'index'])
        ->name('projects.kommersant-ranking');
    Route::get('/projects/vacancy-source', [VacancySourceController::class, 'index'])
        ->name('projects.vacancy-source');
    Route::post('/projects/vacancy-source/investigations', [VacancySourceController::class, 'store'])
        ->name('projects.vacancy-source.investigations.store');
    Route::get('/projects/vacancy-source/investigations/{investigation}/status', [VacancySourceController::class, 'status'])
        ->name('projects.vacancy-source.investigations.status');
    Route::post('/projects/vacancy-source/investigations/{investigation}/cancel', [VacancySourceController::class, 'cancel'])
        ->name('projects.vacancy-source.investigations.cancel');
    Route::patch('/projects/kommersant-ranking/managers/{manager}', [KommersantRankingController::class, 'updateManager'])
        ->name('projects.kommersant-ranking.managers.update');
    Route::patch('/projects/kommersant-ranking/candidates/{candidate}', [KommersantRankingController::class, 'updateCandidate'])
        ->name('projects.kommersant-ranking.candidates.update');

    Route::prefix('/projects/cio-presentations')->group(function () {
        Route::post('/sources', [CioPresentationController::class, 'storeSource'])->name('projects.cio-presentations.sources.store');
        Route::post('/sources/{source}/scan', [CioPresentationController::class, 'scanSource'])->name('projects.cio-presentations.sources.scan');
        Route::post('/presentations', [CioPresentationController::class, 'storePresentation'])->name('projects.cio-presentations.presentations.store');
        Route::delete('/presentations', [CioPresentationController::class, 'clearPresentations'])->name('projects.cio-presentations.presentations.clear');
        Route::patch('/presentations/{presentation}', [CioPresentationController::class, 'updatePresentation'])->name('projects.cio-presentations.presentations.update');
    });

    Route::middleware(EnsureUserIsAdmin::class)->group(function () {
        Route::get('/stas', fn () => Inertia::render('Stas'))->name('stas');
        Route::get('/design-system', fn () => Inertia::render('DesignSystem'))->name('design-system');
        Route::get('/tests', fn () => Inertia::render('Tests'))->name('tests');
        Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::post('/admin/users/{user}/telegram-invite', [AdminTelegramBindingController::class, 'store'])
            ->name('admin.users.telegram-invite.store');
        Route::delete('/admin/users/{user}/telegram-binding', [AdminTelegramBindingController::class, 'destroy'])
            ->name('admin.users.telegram-binding.destroy');

        Route::get('/admin/telegram-reader', [AdminTelegramReaderController::class, 'index'])
            ->name('admin.telegram-reader');
        Route::post('/admin/telegram-reader/request-code', [AdminTelegramReaderController::class, 'requestCode'])
            ->name('admin.telegram-reader.request-code');
        Route::post('/admin/telegram-reader/submit-code', [AdminTelegramReaderController::class, 'submitCode'])
            ->name('admin.telegram-reader.submit-code');
        Route::post('/admin/telegram-reader/submit-password', [AdminTelegramReaderController::class, 'submitPassword'])
            ->name('admin.telegram-reader.submit-password');
        Route::post('/admin/telegram-reader/select-folder', [AdminTelegramReaderController::class, 'selectFolder'])
            ->name('admin.telegram-reader.select-folder');
        Route::post('/admin/telegram-reader/sync', [AdminTelegramReaderController::class, 'syncNow'])
            ->name('admin.telegram-reader.sync');

    });

    Route::get('/push/config', [PushSubscriptionController::class, 'config'])->name('push.config');
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscriptions.store');
    Route::post('/push/test', [PushSubscriptionController::class, 'test'])->name('push.test');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');
    Route::post('/logout', LogoutController::class)->name('logout');
});
