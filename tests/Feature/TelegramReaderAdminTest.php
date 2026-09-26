<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TelegramReaderClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class TelegramReaderAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'username' => 'reader-admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    private function user(): User
    {
        return User::query()->create([
            'username' => 'reader-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);
    }

    public function test_guest_and_regular_user_cannot_open_reader_admin(): void
    {
        $this->get('/admin/telegram-reader')->assertRedirect('/login');
        $this->actingAs($this->user())
            ->get('/admin/telegram-reader')
            ->assertForbidden();
    }

    public function test_admin_can_open_reader_admin_with_service_state(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('status')->once()->andReturn([
            'connected' => true,
            'authorized' => true,
            'auth_state' => 'authorized',
            'account' => [
                'username' => 'work_account',
                'first_name' => 'Work',
            ],
            'selected_folder' => [
                'id' => 7,
                'title' => 'Рабочие вакансии',
            ],
            'sync_running' => false,
            'last_error' => null,
            'chat_count' => 28,
            'indexed_message_count' => 412,
            'last_sync_at' => now()->toIso8601String(),
            'fts_enabled' => true,
        ]);
        $reader->shouldReceive('folders')->once()->andReturn([
            [
                'id' => 7,
                'title' => 'Рабочие вакансии',
                'explicit_chat_count' => 28,
            ],
        ]);

        $this->app->instance(TelegramReaderClient::class, $reader);

        $this->actingAs($this->admin())
            ->get('/admin/telegram-reader')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AdminTelegramReader')
                ->where('reader.authorized', true)
                ->where('reader.chat_count', 28)
                ->where('folders.0.id', 7)
            );
    }

    public function test_admin_can_start_qr_login(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('requestQrLogin')
            ->once()
            ->andReturn([
                'authorized' => false,
                'auth_state' => 'qr_pending',
                'qr_image' => 'data:image/svg+xml;base64,PHN2Zy8+',
                'qr_expires_at' => now()->addMinute()->toIso8601String(),
            ]);

        $this->app->instance(TelegramReaderClient::class, $reader);

        $this->actingAs($this->admin())
            ->post('/admin/telegram-reader/request-qr')
            ->assertRedirect()
            ->assertSessionHas('reader_message');
    }

    public function test_admin_can_request_login_code_without_persisting_phone_in_session(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('requestCode')
            ->once()
            ->with('+79991234567')
            ->andReturn([
                'authorized' => false,
                'auth_state' => 'code_sent',
            ]);

        $this->app->instance(TelegramReaderClient::class, $reader);

        $this->actingAs($this->admin())
            ->post('/admin/telegram-reader/request-code', [
                'phone' => '+79991234567',
            ])
            ->assertRedirect()
            ->assertSessionHas('reader_message');

        $this->assertFalse(session()->hasOldInput('phone'));
    }

    public function test_admin_can_submit_code_and_receive_2fa_state(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('submitCode')
            ->once()
            ->with('12345')
            ->andReturn([
                'authorized' => false,
                'auth_state' => 'password_required',
            ]);

        $this->app->instance(TelegramReaderClient::class, $reader);

        $this->actingAs($this->admin())
            ->post('/admin/telegram-reader/submit-code', [
                'code' => '12345',
            ])
            ->assertRedirect()
            ->assertSessionHas('reader_message', 'Telegram запросил пароль 2FA. Введи его ниже.');

        $this->assertFalse(session()->hasOldInput('code'));
    }

    public function test_admin_can_select_folder_and_start_sync(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('selectFolder')
            ->once()
            ->with(9)
            ->andReturn([
                'selected_folder' => [
                    'id' => 9,
                    'title' => 'Vacancies',
                    'explicit_chat_count' => 31,
                ],
            ]);
        $reader->shouldReceive('syncNow')
            ->once()
            ->andReturn(['accepted' => true]);

        $this->app->instance(TelegramReaderClient::class, $reader);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/telegram-reader/select-folder', [
                'folder_id' => 9,
            ])
            ->assertRedirect()
            ->assertSessionHas('reader_message');

        $this->actingAs($admin)
            ->post('/admin/telegram-reader/sync')
            ->assertRedirect()
            ->assertSessionHas('reader_message');
    }
}
