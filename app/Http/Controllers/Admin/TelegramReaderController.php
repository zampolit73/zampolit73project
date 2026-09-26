<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TelegramReaderClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TelegramReaderController extends Controller
{
    public function __construct(
        private readonly TelegramReaderClient $reader,
    ) {
    }

    public function index(): Response
    {
        $status = [
            'connected' => false,
            'authorized' => false,
            'auth_state' => 'unavailable',
            'account' => null,
            'qr_image' => null,
            'qr_expires_at' => null,
            'selected_folder' => null,
            'sync_running' => false,
            'last_error' => null,
            'chat_count' => 0,
            'indexed_message_count' => 0,
            'last_sync_at' => null,
            'fts_enabled' => false,
        ];
        $folders = [];
        $serviceError = null;

        try {
            $status = array_merge($status, $this->reader->status());

            if ($status['authorized']) {
                $folders = $this->reader->folders();
            }
        } catch (RuntimeException $exception) {
            $serviceError = $exception->getMessage();
        }

        return Inertia::render('AdminTelegramReader', [
            'reader' => $status,
            'folders' => $folders,
            'serviceError' => $serviceError,
        ]);
    }

    public function requestQrLogin(): RedirectResponse
    {
        return $this->readerAction(
            fn () => $this->reader->requestQrLogin(),
            'QR-код готов. Открой Telegram → Настройки → Устройства → Подключить устройство и отсканируй его.',
        );
    }

    public function requestCode(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('phone'), [
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ], [
            'phone.regex' => 'Номер нужен в международном формате, например +79991234567.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        return $this->readerAction(
            fn () => $this->reader->requestCode(trim((string) $request->input('phone'))),
            'Код входа отправлен Telegram. Введи его ниже.',
        );
    }

    public function submitCode(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('code'), [
            'code' => ['required', 'string', 'regex:/^[0-9]{4,8}$/'],
        ], [
            'code.regex' => 'Проверь код Telegram.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        try {
            $result = $this->reader->submitCode(trim((string) $request->input('code')));
        } catch (RuntimeException $exception) {
            return back()->with('reader_error', $exception->getMessage());
        }

        if (($result['auth_state'] ?? null) === 'password_required') {
            return back()->with('reader_message', 'Telegram запросил пароль 2FA. Введи его ниже.');
        }

        return back()->with('reader_message', 'Telegram Reader успешно авторизован.');
    }

    public function submitPassword(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('password'), [
            'password' => ['required', 'string', 'max:512'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        return $this->readerAction(
            fn () => $this->reader->submitPassword((string) $request->input('password')),
            'Telegram Reader успешно авторизован.',
        );
    }

    public function selectFolder(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->only('folder_id'), [
            'folder_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator);
        }

        return $this->readerAction(
            fn () => $this->reader->selectFolder((int) $request->integer('folder_id')),
            'Рабочая Telegram-папка выбрана. Начался backfill последних 3 месяцев.',
        );
    }

    public function syncNow(): RedirectResponse
    {
        return $this->readerAction(
            fn () => $this->reader->syncNow(),
            'Синхронизация Telegram Reader запущена.',
        );
    }

    private function readerAction(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $exception) {
            return back()->with('reader_error', $exception->getMessage());
        }

        return back()->with('reader_message', $successMessage);
    }
}
