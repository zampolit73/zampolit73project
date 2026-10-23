<?php

namespace App\Services;

use App\Models\CompanyResearchTelegramPending;
use App\Models\UserTelegramAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramResearchImportHandler
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly CompanyResearchImportService $imports,
    ) {
    }

    /**
     * Uploads arrive only in private messages from verified, linked employees.
     * This returns a Telegram sendMessage-compatible reply, with inline buttons.
     */
    public function document(array $message, UserTelegramAccount $binding): array
    {
        $chatId = (int) data_get($message, 'chat.id');
        $messageId = (int) data_get($message, 'message_id');
        if ($chatId !== (int) $binding->telegram_chat_id) {
            return $this->reply($chatId, 'Чат Telegram не совпадает с привязанным аккаунтом сотрудника.');
        }
        $fileName = data_get($message, 'document.file_name');
        $fileId = data_get($message, 'document.file_id');
        $fileSize = data_get($message, 'document.file_size');

        if (! is_string($fileName) || ! preg_match('/\.json$/i', $fileName)
            || ! is_string($fileId) || $fileId === '' || $messageId <= 0) {
            return $this->reply($chatId, 'Для импорта пришли JSON-файл из IRLIX Sales Analyst как документ (не текст и не Word).');
        }

        if (! is_numeric($fileSize) || $fileSize > 262144 || $fileSize < 1) {
            return $this->reply($chatId, 'JSON слишком большой или размер файла неизвестен. Максимум 256 КБ.');
        }

        // Poller/webhook retries must never create a second confirmation for one message.
        $old = CompanyResearchTelegramPending::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_message_id', $messageId)->first();
        if ($old) {
            return $this->reply($chatId, 'Этот JSON уже принят. Используй кнопки под первым ответом бота или пришли новый файл.');
        }

        $bytes = $this->bot->downloadJson($fileId);
        if ($bytes === null) {
            return $this->reply($chatId, 'Telegram не отдал файл. Проверь соединение и отправь JSON ещё раз.');
        }

        try {
            $report = $this->imports->prepare($bytes);
        } catch (ValidationException $exception) {
            return $this->reply($chatId, 'JSON отклонён: '.$this->errorText($exception));
        }

        // Clear stale pending source bytes, never keep unconfirmed sensitive data forever.
        CompanyResearchTelegramPending::query()
            ->where('state', 'pending')->where('expires_at', '<', now())
            ->update(['state' => 'expired', 'payload' => null, 'completed_at' => now()]);

        $duplicate = $this->imports->existing($report);
        $nonce = bin2hex(random_bytes(12));
        $payload = json_encode(['schema_version' => 1] + $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $pending = CompanyResearchTelegramPending::query()->create([
            'user_id' => $binding->user_id,
            'telegram_user_id' => $binding->telegram_user_id,
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => $messageId,
            'report_id' => $report['id'],
            'company_name' => $report['company']['name'],
            'had_collision' => $duplicate !== null,
            'payload' => $payload,
            'token_hash' => hash('sha256', $nonce),
            'expires_at' => now()->addMinutes(30),
        ]);

        $action = $duplicate ? 'Обновить (новая версия)' : 'Сохранить компанию';
        $text = 'IRLIX Intelligence: JSON проверен.'."\n"
            .'Компания: '.$report['company']['name']."\n"
            .'ИНН: '.($report['company']['inn'] ?: 'не указан')."\n"
            .'ЛПР: '.count($report['decision_makers'])
            .' · Источники: '.count($report['evidence'])
            .' · Тендеры: '.count($report['tenders'])."\n"
            .'Оценка: '.$report['sales_assessment']['grade']."\n\n"
            .($duplicate
                ? '⚠ Уже есть карточка «'.$duplicate['name'].'». Обновление сохранит прежние версии SQLite.'
                : 'Новая карточка. Будет сохранена только после твоего подтверждения.')
            ."\n".'Кнопки действуют 30 минут; файл не публикуется в GitHub.';

        return $this->reply($chatId, $text, [
            'inline_keyboard' => [[
                ['text' => '✅ '.$action, 'callback_data' => 'irlix:save:'.$pending->id.':'.$nonce],
                ['text' => '❌ Отмена', 'callback_data' => 'irlix:cancel:'.$pending->id.':'.$nonce],
            ]],
        ]);
    }

    /**
     * Only the original Telegram identity, private chat and currently linked site
     * account can commit or cancel a previously prepared import.
     */
    public function callback(array $query): ?array
    {
        $callbackId = data_get($query, 'id');
        $data = data_get($query, 'data');
        $chatId = data_get($query, 'message.chat.id');
        $telegramId = data_get($query, 'from.id');
        if (! is_string($data) || ! preg_match('/^irlix:(save|cancel):([1-9]\d*):([a-f0-9]{24})$/', $data, $matches)
            || data_get($query, 'message.chat.type') !== 'private'
            || ! is_numeric($telegramId) || ! is_numeric($chatId)) {
            return null;
        }

        $this->bot->answerCallback(is_string($callbackId) ? $callbackId : '', 'Проверяем…');
        $telegramId = (int) $telegramId;
        $chatId = (int) $chatId;

        $binding = UserTelegramAccount::query()
            ->where('telegram_user_id', $telegramId)
            ->where('telegram_chat_id', $chatId)->first();
        if (! $binding) {
            return $this->reply($chatId, 'Нет действующей привязки Telegram к сотруднику IRLIX. Импорт запрещён.');
        }

        $reply = DB::transaction(function () use ($binding, $telegramId, $chatId, $matches) {
            $pending = CompanyResearchTelegramPending::query()
                ->whereKey((int) $matches[2])
                ->lockForUpdate()->first();

            if (! $pending || $pending->user_id !== $binding->user_id
                || $pending->telegram_user_id !== $telegramId
                || $pending->telegram_chat_id !== $chatId
                || ! hash_equals($pending->token_hash, hash('sha256', $matches[3]))) {
                return $this->reply($chatId, 'Невозможно подтвердить чужой или недействительный импорт.');
            }

            if ($pending->state === 'applied') {
                $version = $pending->result_revision
                    ? ', версия '.$pending->result_revision : '';
                $status = $pending->result_changed === false ? 'без изменений' : 'уже сохранено';

                return $this->terminalReply($chatId,
                    '✅ Исследование «'.$pending->company_name.'» '.$status.$version
                    .".\n".'Повторная запись не создаётся.'
                    ."\n".'Открыть: https://zampolit73.duckdns.org/projects/irlix-sales/research');
            }

            if ($pending->state !== 'pending') {
                $message = match ($pending->state) {
                    'cancelled' => '❌ Импорт «'.$pending->company_name.'» уже отменён.',
                    'expired' => 'Срок подтверждения истёк. Пришли JSON заново.',
                    'stale' => 'Карточка уже появилась в базе. Пришли JSON заново для обновления.',
                    'rejected' => 'Импорт ранее отклонён. Исправь JSON и пришли заново.',
                    default => 'Это действие уже завершено. Повторной записи не было.',
                };

                return $this->terminalReply($chatId, $message);
            }

            if ($pending->expires_at->isPast()) {
                $pending->update(['state' => 'expired', 'payload' => null, 'completed_at' => now()]);
                return $this->terminalReply($chatId, 'Срок подтверждения истёк. Пришли файл заново.');
            }

            if ($matches[1] === 'cancel') {
                $pending->update(['state' => 'cancelled', 'payload' => null, 'completed_at' => now()]);
                return $this->terminalReply($chatId, '❌ Импорт «'.$pending->company_name.'» отменён. На сайте ничего не изменилось.');
            }

            try {
                $report = $this->imports->prepare((string) $pending->payload);
                // If a new company appeared between preview and confirmation, do
                // not silently convert "Save" into an overwriting operation.
                if (! $pending->had_collision && $this->imports->existing($report)) {
                    $pending->update(['state' => 'stale', 'payload' => null, 'completed_at' => now()]);
                    return $this->terminalReply($chatId, 'Компания появилась после отправки файла. Пришли JSON заново для подтверждения обновления.');
                }
                $saved = $this->imports->save($report, $binding->user_id, $pending->had_collision);
            } catch (ValidationException $exception) {
                $pending->update(['state' => 'rejected', 'payload' => null, 'completed_at' => now()]);
                return $this->terminalReply($chatId, 'Импорт не выполнен: '.$this->errorText($exception));
            }

            $pending->update([
                'state' => 'applied', 'payload' => null, 'completed_at' => now(),
                'result_revision' => $saved['revision'],
                'result_changed' => $saved['changed'],
            ]);
            return $this->terminalReply($chatId,
                '✅ '.($saved['changed'] ? 'Исследование сохранено' : 'Исследование без изменений')
                .': '.$saved['name']."\n"
                .'Версия '.$saved['revision']."\n"
                .'Автор: '.$binding->user?->username."\n"
                .'Открой IRLIX Intelligence: https://zampolit73.duckdns.org/projects/irlix-sales/research');
        });

        // Telegram transport sends the success message FIRST and removes buttons
        // only after successful delivery. On delivery failure, retry the update
        // safely: the saved outcome is persisted and the callback is idempotent.
        if ($reply['clear_inline_keyboard'] ?? false) {
            $messageId = data_get($query, 'message.message_id');
            if (is_numeric($messageId) && (int) $messageId > 0) {
                $reply['clear_inline_keyboard'] = (int) $messageId;
            } else {
                unset($reply['clear_inline_keyboard']);
            }
        }

        return $reply;
    }

    private function terminalReply(int $chatId, string $text): array
    {
        return $this->reply($chatId, $text) + ['clear_inline_keyboard' => true];
    }

    private function errorText(ValidationException $exception): string
    {
        $first = collect($exception->errors())->flatten()->first();
        return Str::limit((string) ($first ?: 'Некорректный JSON.'), 240);
    }

    private function reply(int $chatId, string $text, ?array $markup = null): array
    {
        $response = ['chat_id' => $chatId, 'text' => Str::limit($text, 4000, '…')];
        if ($markup !== null) {
            $response['reply_markup'] = $markup;
        }

        return $response;
    }
}
