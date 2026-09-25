<?php

use App\Http\Controllers\Api\TelegramBotWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/bot/webhook', TelegramBotWebhookController::class)
    ->name('api.telegram.bot.webhook');
