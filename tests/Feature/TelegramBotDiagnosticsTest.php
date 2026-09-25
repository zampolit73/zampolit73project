<?php

namespace Tests\Feature;

use App\Models\TelegramInvite;
use App\Models\User;
use App\Models\UserTelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramBotDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_print_safe_state_and_probe_result(): void
    {
        config()->set('services.telegram.token', '123456:test-token');
        config()->set('services.telegram.webhook_secret', 'test_webhook_secret');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'id' => 123456,
                    'is_bot' => true,
                    'username' => 'vacancy_test_bot',
                ],
            ], 200),
        ]);

        $user = User::query()->create([
            'username' => 'telegram-diagnostics',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        UserTelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 123456789,
            'telegram_chat_id' => 123456789,
            'telegram_username' => 'linked_user',
            'linked_at' => now(),
        ]);

        TelegramInvite::query()->create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', 'USED-CODE'),
            'used_at' => now(),
            'used_by_telegram_user_id' => 123456789,
        ]);

        $this->artisan('telegram:bot:diagnose')
            ->expectsOutputToContain('token_configured=yes')
            ->expectsOutputToContain('webhook_secret_configured=yes')
            ->expectsOutputToContain('linked_accounts=1')
            ->expectsOutputToContain('used_invites=1')
            ->expectsOutputToContain('unused_invites=0')
            ->expectsOutputToContain('outbound_api=ok @vacancy_test_bot')
            ->assertExitCode(0);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getMe'));
    }

    public function test_diagnostics_do_not_fail_when_telegram_is_unreachable(): void
    {
        config()->set('services.telegram.token', '123456:test-token');
        config()->set('services.telegram.webhook_secret', 'test_webhook_secret');

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Bad Request',
            ], 400),
        ]);

        $this->artisan('telegram:bot:diagnose')
            ->expectsOutputToContain('outbound_api=failed Bad Request')
            ->assertExitCode(0);
    }
}
