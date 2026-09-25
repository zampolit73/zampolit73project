<?php

namespace Tests\Feature;

use App\Jobs\RunVacancyInvestigation;
use App\Models\TelegramInvite;
use App\Models\User;
use App\Models\UserTelegramAccount;
use App\Models\VacancyInvestigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram.token', '123456:test-token');
        config()->set('services.telegram.webhook_secret', 'test_webhook_secret');
        config()->set('services.telegram.username', 'vacancy_test_bot');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [],
            ], 200),
        ]);
    }

    private function user(string $username = 'telegram-target'): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);
    }

    private function webhook(array $payload, string $secret = 'test_webhook_secret')
    {
        return $this->postJson(
            '/api/telegram/bot/webhook',
            $payload,
            ['X-Telegram-Bot-Api-Secret-Token' => $secret],
        );
    }

    public function test_webhook_rejects_wrong_secret(): void
    {
        $this->webhook([
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'from' => ['id' => 100],
                'chat' => ['id' => 100, 'type' => 'private'],
                'text' => '/start',
            ],
        ], 'wrong-secret')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_start_code_binds_telegram_to_existing_site_user_once(): void
    {
        $user = $this->user();
        $code = 'ABCD-EFGH';

        TelegramInvite::query()->create([
            'user_id' => $user->id,
            'created_by_user_id' => null,
            'code_hash' => hash('sha256', $code),
        ]);

        $this->webhook([
            'update_id' => 2,
            'message' => [
                'message_id' => 11,
                'from' => [
                    'id' => 987654321,
                    'username' => 'sales_person',
                ],
                'chat' => [
                    'id' => 987654321,
                    'type' => 'private',
                ],
                'text' => '/start '.$code,
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('user_telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 987654321,
            'telegram_chat_id' => 987654321,
            'telegram_username' => 'sales_person',
        ]);

        $invite = TelegramInvite::query()->firstOrFail();
        $this->assertNotNull($invite->used_at);
        $this->assertSame(987654321, $invite->used_by_telegram_user_id);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Telegram привязан'));
    }

    public function test_bound_user_can_send_forward_text_into_same_investigation_queue(): void
    {
        Queue::fake();

        $user = $this->user();

        UserTelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 555001,
            'telegram_chat_id' => 555001,
            'telegram_username' => 'forwarder',
            'linked_at' => now(),
        ]);

        $text = 'Senior Java developer: Kafka, Camunda, PostgreSQL, highload и микросервисы.';

        $this->webhook([
            'update_id' => 3,
            'message' => [
                'message_id' => 12,
                'from' => [
                    'id' => 555001,
                    'username' => 'forwarder',
                ],
                'chat' => [
                    'id' => 555001,
                    'type' => 'private',
                ],
                'forward_origin' => [
                    'type' => 'hidden_user',
                    'sender_user_name' => 'Should Not Be Used',
                ],
                'text' => $text,
            ],
        ])->assertOk();

        $investigation = VacancyInvestigation::query()->firstOrFail();

        $this->assertSame($user->id, $investigation->user_id);
        $this->assertSame('telegram', $investigation->input_source);
        $this->assertSame($text, $investigation->input_text);
        $this->assertSame('queued', $investigation->status);

        Queue::assertPushedOn('vacancy-source', RunVacancyInvestigation::class);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'поставлена в очередь'));
    }

    public function test_unbound_private_user_is_told_to_request_admin_code(): void
    {
        $this->webhook([
            'update_id' => 4,
            'message' => [
                'message_id' => 13,
                'from' => ['id' => 701],
                'chat' => ['id' => 701, 'type' => 'private'],
                'text' => 'Java developer vacancy with Kafka and PostgreSQL requirements.',
            ],
        ])->assertOk();

        $this->assertDatabaseCount('vacancy_investigations', 0);

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'не привязан'));
    }

    public function test_group_messages_are_ignored(): void
    {
        $this->webhook([
            'update_id' => 5,
            'message' => [
                'message_id' => 14,
                'from' => ['id' => 702],
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
                'text' => 'A long vacancy message that must not become an investigation.',
            ],
        ])->assertOk();

        $this->assertDatabaseCount('vacancy_investigations', 0);
        Http::assertNothingSent();
    }
}
