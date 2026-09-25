<?php

namespace Tests\Feature;

use App\Jobs\RunVacancyInvestigation;
use App\Models\TelegramInvite;
use App\Models\User;
use App\Models\UserTelegramAccount;
use App\Models\VacancyInvestigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        config()->set('services.telegram.push_enabled', false);
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
    }

    public function test_start_code_binds_telegram_and_replies_via_webhook_response(): void
    {
        $user = $this->user();
        $code = 'ABCD-EFGH';

        TelegramInvite::query()->create([
            'user_id' => $user->id,
            'created_by_user_id' => null,
            'code_hash' => hash('sha256', $code),
        ]);

        $response = $this->webhook([
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
        ])->assertOk();

        $response
            ->assertJsonPath('method', 'sendMessage')
            ->assertJsonPath('chat_id', 987654321);

        $this->assertStringContainsString('Telegram привязан', (string) $response->json('text'));

        $this->assertDatabaseHas('user_telegram_accounts', [
            'user_id' => $user->id,
            'telegram_user_id' => 987654321,
            'telegram_chat_id' => 987654321,
            'telegram_username' => 'sales_person',
        ]);

        $invite = TelegramInvite::query()->firstOrFail();
        $this->assertNotNull($invite->used_at);
        $this->assertSame(987654321, $invite->used_by_telegram_user_id);
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

        $response = $this->webhook([
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

        $response->assertJsonPath('method', 'sendMessage');
        $this->assertStringContainsString('поставлена в очередь', (string) $response->json('text'));
        $this->assertStringContainsString('/status', (string) $response->json('text'));
    }

    public function test_status_returns_latest_completed_result_through_webhook_response(): void
    {
        $user = $this->user();

        UserTelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 555002,
            'telegram_chat_id' => 555002,
            'telegram_username' => 'status_user',
            'linked_at' => now(),
        ]);

        VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'telegram',
            'input_text' => 'A sufficiently long vacancy text for the completed investigation.',
            'status' => 'completed',
            'progress_stage' => 'completed',
            'progress_text' => 'Готово',
            'result_summary' => 'Тестовый результат расследования.',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subSeconds(30),
            'finished_at' => now(),
        ]);

        $response = $this->webhook([
            'update_id' => 4,
            'message' => [
                'message_id' => 13,
                'from' => ['id' => 555002, 'username' => 'status_user'],
                'chat' => ['id' => 555002, 'type' => 'private'],
                'text' => '/status',
            ],
        ])->assertOk();

        $response->assertJsonPath('method', 'sendMessage');
        $this->assertStringContainsString('Тестовый результат расследования.', (string) $response->json('text'));
    }

    public function test_unbound_private_user_is_told_to_request_admin_code(): void
    {
        $response = $this->webhook([
            'update_id' => 5,
            'message' => [
                'message_id' => 14,
                'from' => ['id' => 701],
                'chat' => ['id' => 701, 'type' => 'private'],
                'text' => 'Java developer vacancy with Kafka and PostgreSQL requirements.',
            ],
        ])->assertOk();

        $this->assertDatabaseCount('vacancy_investigations', 0);
        $response->assertJsonPath('method', 'sendMessage');
        $this->assertStringContainsString('не привязан', (string) $response->json('text'));
    }

    public function test_group_messages_are_ignored(): void
    {
        $this->webhook([
            'update_id' => 6,
            'message' => [
                'message_id' => 15,
                'from' => ['id' => 702],
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
                'text' => 'A long vacancy message that must not become an investigation.',
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('vacancy_investigations', 0);
    }
}
