<?php

return [
    'translation' => [
        'url' => env('TRANSLATION_API_URL', 'http://127.0.0.1:5000'),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_BOT_WEBHOOK_SECRET'),
        'api_ip' => env('TELEGRAM_BOT_API_IP'),
        'push_enabled' => env('TELEGRAM_BOT_PUSH_ENABLED', false),
    ],
];
