<?php

namespace Tests\Feature;

use App\Models\CompanyResearchImport;
use App\Models\CompanyResearchTelegramPending;
use App\Models\User;
use App\Models\UserTelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramResearchImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.token', '12345:fake-token');
        config()->set('services.telegram.webhook_secret', 'test-webhook-secret');
    }

    private function user(int $telegramId = 77701, string $username = 'sales'): User
    {
        $user = User::query()->create([
            'username' => $username,
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);
        UserTelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => $telegramId,
            'telegram_chat_id' => $telegramId,
            'telegram_username' => $username,
            'linked_at' => now(),
        ]);

        return $user;
    }

    private function report(): array
    {
        return [
            'schema_version' => 1,
            'id' => 'inn-7707083893',
            'company' => ['name' => 'Тестовый заказчик', 'inn' => '7707083893'],
            'researched_at' => '2026-10-23T12:00:00Z',
            'summary' => 'Исследование заказчика.',
            'outstaff_demand' => ['assessment' => 'likely', 'explanation' => 'Косвенный сигнал'],
            'technologies' => ['Java', 'QA'],
            'evidence' => [[
                'title' => 'Закупка',
                'url' => 'https://example.org/tender',
                'finding' => 'Внешняя IT-разработка',
            ]],
            'decision_makers' => [[
                'name' => 'Технический руководитель',
                'role' => 'CTO',
                'source_url' => 'https://example.org/profile',
                'public_url' => 'https://example.org/profile',
                'priority' => 1,
            ]],
            'business_contacts' => [],
            'tenders' => [],
        ];
    }

    private function fakeTelegram(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        Http::fake([
            'https://api.telegram.org/bot*/getFile' => Http::response([
                'ok' => true, 'result' => [
                    'file_path' => 'documents/secure.json', 'file_size' => strlen($json),
                ],
            ]),
            'https://api.telegram.org/file/bot*/*' => Http::response($json, 200, ['Content-Type' => 'application/json']),
            'https://api.telegram.org/bot*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true]),
        ]);
    }

    private function document(int $senderId = 77701, int $messageId = 100, string $name = 'report.json', int $size = 100): array
    {
        return ['update_id' => $messageId + 8000, 'message' => [
            'message_id' => $messageId,
            'from' => ['id' => $senderId, 'username' => 'sales'],
            'chat' => ['id' => $senderId, 'type' => 'private'],
            'document' => [
                'file_id' => 'secure-file-id', 'file_name' => $name, 'file_size' => $size,
            ],
        ]];
    }

    private function webhook(array $update)
    {
        return $this->postJson('/api/telegram/bot/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret',
        ]);
    }

    private function callbackUpdate(string $data, int $senderId = 77701): array
    {
        return [
            'update_id' => 9001,
            'callback_query' => [
                'id' => 'callback-id-123',
                'from' => ['id' => $senderId],
                'data' => $data,
                'message' => [
                    'message_id' => 777,
                    'chat' => ['id' => $senderId, 'type' => 'private'],
                ],
            ],
        ];
    }

    private function uploadAndGetAction(int $messageId = 100, array $payload = null): array
    {
        $this->fakeTelegram($payload ?? $this->report());
        $response = $this->webhook($this->document(messageId: $messageId))->assertOk();
        $response->assertJsonPath('method', 'sendMessage');
        $buttons = $response->json('reply_markup.inline_keyboard.0');
        $this->assertCount(2, $buttons);

        return [$buttons[0]['callback_data'], $buttons[1]['callback_data'], $response->json('text')];
    }

    public function test_bound_employee_receives_preview_and_saves_only_after_confirmation(): void
    {
        $user = $this->user();
        [$save, $cancel, $message] = $this->uploadAndGetAction();
        $this->assertStringContainsString('Тестовый заказчик', $message);
        $this->assertStringContainsString('ЛПР: 1', $message);
        $this->assertDatabaseCount('company_research_imports', 0);
        $this->assertDatabaseCount('company_research_telegram_pending', 1);

        $this->webhook($this->callbackUpdate($save))
            ->assertOk()->assertJsonPath('method', 'sendMessage');
        $this->assertDatabaseHas('company_research_imports', [
            'identifier' => 'inn-7707083893',
            'revision' => 1,
            'imported_by' => $user->id,
        ]);
        $this->assertDatabaseCount('company_research_import_revisions', 1);
        $this->assertDatabaseHas('company_research_telegram_pending', [
            'state' => 'applied', 'payload' => null,
        ]);
        $this->assertFileDoesNotExist(base_path('data/company-reports/inn-7707083893.json'));
    }

    public function test_duplicate_upload_requires_confirmation_and_preserves_versions(): void
    {
        $this->user();
        [$save] = $this->uploadAndGetAction();
        $this->webhook($this->callbackUpdate($save))->assertOk();

        $changed = $this->report();
        $changed['summary'] = 'Новые сведения';
        [$again, , $message] = $this->uploadAndGetAction(101, $changed);
        $this->assertStringContainsString('Уже есть карточка', $message);
        $this->assertStringContainsString('Обновить', $message);
        $this->assertDatabaseHas('company_research_imports', ['revision' => 1]);

        $this->webhook($this->callbackUpdate($again))->assertOk();
        $this->assertDatabaseCount('company_research_imports', 1);
        $this->assertDatabaseCount('company_research_import_revisions', 2);
        $this->assertSame(2, CompanyResearchImport::query()->firstOrFail()->revision);

        $this->webhook($this->callbackUpdate($again))->assertOk();
        $this->assertDatabaseCount('company_research_import_revisions', 2);
    }

    public function test_cancel_never_writes_research(): void
    {
        $this->user();
        [, $cancel] = $this->uploadAndGetAction();
        $this->webhook($this->callbackUpdate($cancel))
            ->assertOk()->assertJsonPath('method', 'sendMessage');
        $this->assertDatabaseCount('company_research_imports', 0);
        $this->assertDatabaseHas('company_research_telegram_pending', [
            'state' => 'cancelled', 'payload' => null,
        ]);
    }

    public function test_callback_from_another_employee_cannot_apply_file(): void
    {
        $this->user();
        $this->user(77702, 'other-person');
        [$save] = $this->uploadAndGetAction();

        $response = $this->webhook($this->callbackUpdate($save, 77702))->assertOk();
        $this->assertStringContainsString('недействительный импорт', (string) $response->json('text'));
        $this->assertDatabaseCount('company_research_imports', 0);
        $this->assertDatabaseHas('company_research_telegram_pending', ['state' => 'pending']);
    }

    public function test_unbound_telegram_sender_cannot_download_or_import(): void
    {
        $this->fakeTelegram($this->report());
        $response = $this->webhook($this->document(99999))->assertOk();
        $this->assertStringContainsString('не привязан', (string) $response->json('text'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('company_research_telegram_pending', 0);
    }

    public function test_invalid_or_oversized_documents_are_rejected(): void
    {
        $this->user();
        $this->fakeTelegram($this->report());
        $this->webhook($this->document(name: 'report.docx'))->assertOk();
        $this->webhook($this->document(messageId: 101, size: 999999))->assertOk();
        Http::assertNothingSent();
        $this->assertDatabaseCount('company_research_telegram_pending', 0);
    }

    public function test_invalid_json_is_rejected_without_pending_record(): void
    {
        $this->user();
        $bad = $this->report();
        $bad['decision_makers'][0]['source_url'] = 'javascript:alert(1)';
        $this->fakeTelegram($bad);
        $response = $this->webhook($this->document())->assertOk();
        $this->assertStringContainsString('отклонён', (string) $response->json('text'));
        $this->assertDatabaseCount('company_research_telegram_pending', 0);
    }

    public function test_expired_confirmation_does_not_write(): void
    {
        $this->user();
        [$save] = $this->uploadAndGetAction();
        CompanyResearchTelegramPending::query()->update(['expires_at' => now()->subMinute()]);
        $this->webhook($this->callbackUpdate($save))->assertOk();
        $this->assertDatabaseCount('company_research_imports', 0);
        $this->assertDatabaseHas('company_research_telegram_pending', ['state' => 'expired']);
    }

    public function test_repeated_telegram_delivery_does_not_duplicate_pending_record(): void
    {
        $this->user();
        $this->fakeTelegram($this->report());
        $this->webhook($this->document())->assertOk();
        $this->webhook($this->document())->assertOk();
        $this->assertDatabaseCount('company_research_telegram_pending', 1);
        Http::assertSentCount(2); // getFile + file download, not duplicated
    }

    public function test_web_import_and_telegram_import_share_same_dossier_rules(): void
    {
        $this->user();
        $bad = $this->report();
        $bad['outstaff_demand']['assessment'] = 'confirmed';
        $bad['evidence'] = [];
        $this->fakeTelegram($bad);
        $resp = $this->webhook($this->document())->assertOk();
        $this->assertStringContainsString('отклонён', (string) $resp->json('text'));
        $this->assertDatabaseCount('company_research_imports', 0);
    }
}
