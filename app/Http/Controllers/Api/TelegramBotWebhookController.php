<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunVacancyInvestigation;
use App\Models\TelegramInvite;
use App\Models\UserTelegramAccount;
use App\Models\VacancyInvestigation;
use App\Services\TelegramBotClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TelegramBotWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBotClient $bot): JsonResponse
    {
        if (! $this->hasValidSecret($request)) {
            abort(403);
        }

        $message = $request->input('message');

        if (! is_array($message) || data_get($message, 'chat.type') !== 'private') {
            return $this->ack();
        }

        $telegramUserId = data_get($message, 'from.id');
        $chatId = data_get($message, 'chat.id');

        if (! is_numeric($telegramUserId) || ! is_numeric($chatId)) {
            return $this->ack();
        }

        $telegramUserId = (int) $telegramUserId;
        $chatId = (int) $chatId;
        $telegramUsername = data_get($message, 'from.username');
        $text = trim((string) (data_get($message, 'text') ?: data_get($message, 'caption') ?: ''));

        if (preg_match('/^\/start(?:@\w+)?(?:\s+([A-Za-z0-9_-]+))?/u', $text, $matches)) {
            $code = isset($matches[1]) ? strtoupper(trim($matches[1])) : null;

            $this->handleStart(
                $bot,
                $telegramUserId,
                $chatId,
                is_string($telegramUsername) ? $telegramUsername : null,
                $code,
            );

            return $this->ack();
        }

        $binding = UserTelegramAccount::query()
            ->with('user:id,username')
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if (! $binding) {
            $bot->sendMessage(
                $chatId,
                'Telegram пока не привязан к аккаунту сайта. Получи код у администратора и отправь сюда /start КОД.',
            );

            return $this->ack();
        }

        if ($text === '' || str_starts_with($text, '/')) {
            $bot->sendMessage(
                $chatId,
                'Отправь сюда текст вакансии обычным сообщением или Forward. Для анализа используется только текст сообщения.',
            );

            return $this->ack();
        }

        if (mb_strlen($text) < 20) {
            $bot->sendMessage(
                $chatId,
                'Данных пока слишком мало. Пришли более полный текст вакансии — хотя бы роль, стек и несколько требований.',
            );

            return $this->ack();
        }

        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $binding->user_id,
            'input_source' => 'telegram',
            'input_text' => $text,
            'status' => 'queued',
            'progress_stage' => 'queued',
            'progress_text' => 'Проверка поставлена в очередь',
            'queued_at' => now(),
        ]);

        RunVacancyInvestigation::dispatch($investigation->id);

        $bot->sendMessage(
            $chatId,
            'Проверка #'.$investigation->id.' поставлена в очередь. Напишу сюда по этапам и когда результат будет готов.',
        );

        return $this->ack();
    }

    private function handleStart(
        TelegramBotClient $bot,
        int $telegramUserId,
        int $chatId,
        ?string $telegramUsername,
        ?string $code,
    ): void {
        $existing = UserTelegramAccount::query()
            ->with('user:id,username')
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if ($code === null) {
            $message = $existing
                ? 'Telegram уже привязан к аккаунту «'.$existing->user->username.'». Можешь присылать сюда вакансии.'
                : 'Чтобы подключить Telegram, получи одноразовый код у администратора и отправь /start КОД.';

            $bot->sendMessage($chatId, $message);

            return;
        }

        if ($existing) {
            $bot->sendMessage(
                $chatId,
                'Этот Telegram уже привязан к аккаунту «'.$existing->user->username.'». Если нужно сменить пользователя, администратор сначала должен отвязать текущую привязку.',
            );

            return;
        }

        $result = $this->bindInvite($code, $telegramUserId, $chatId, $telegramUsername);

        if ($result['status'] === 'invalid') {
            $bot->sendMessage($chatId, 'Код не найден или уже использован. Попроси администратора создать новый Telegram-код.');

            return;
        }

        if ($result['status'] === 'target_bound') {
            $bot->sendMessage($chatId, 'Этот аккаунт сайта уже привязан к другому Telegram. Попроси администратора сначала отвязать его.');

            return;
        }

        if ($result['status'] !== 'ok') {
            $bot->sendMessage($chatId, 'Не удалось завершить привязку. Попроси администратора создать новый Telegram-код.');

            return;
        }

        $bot->sendMessage(
            $chatId,
            'Готово. Telegram привязан к аккаунту «'.$result['username'].'». Теперь можешь отправлять сюда текст вакансии обычным сообщением или Forward.',
        );
    }

    private function bindInvite(
        string $code,
        int $telegramUserId,
        int $chatId,
        ?string $telegramUsername,
    ): array {
        try {
            return DB::transaction(function () use ($code, $telegramUserId, $chatId, $telegramUsername) {
                $invite = TelegramInvite::query()
                    ->with('user:id,username')
                    ->where('code_hash', hash('sha256', strtoupper($code)))
                    ->whereNull('used_at')
                    ->first();

                if (! $invite) {
                    return ['status' => 'invalid'];
                }

                if (UserTelegramAccount::query()->where('user_id', $invite->user_id)->exists()) {
                    return ['status' => 'target_bound'];
                }

                if (UserTelegramAccount::query()->where('telegram_user_id', $telegramUserId)->exists()) {
                    return ['status' => 'telegram_bound'];
                }

                $claimed = TelegramInvite::query()
                    ->whereKey($invite->id)
                    ->whereNull('used_at')
                    ->update([
                        'used_at' => now(),
                        'used_by_telegram_user_id' => $telegramUserId,
                    ]);

                if ($claimed !== 1) {
                    return ['status' => 'invalid'];
                }

                UserTelegramAccount::query()->create([
                    'user_id' => $invite->user_id,
                    'telegram_user_id' => $telegramUserId,
                    'telegram_chat_id' => $chatId,
                    'telegram_username' => $telegramUsername,
                    'linked_at' => now(),
                ]);

                return [
                    'status' => 'ok',
                    'username' => $invite->user->username,
                ];
            });
        } catch (QueryException) {
            return ['status' => 'conflict'];
        }
    }

    private function hasValidSecret(Request $request): bool
    {
        $configuredSecret = trim((string) config('services.telegram.webhook_secret'));
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $configuredSecret !== ''
            && $providedSecret !== ''
            && hash_equals($configuredSecret, $providedSecret);
    }

    private function ack(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
