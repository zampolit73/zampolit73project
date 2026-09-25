<?php

namespace Tests\Feature;

use App\Models\TelegramInvite;
use App\Models\User;
use App\Models\UserTelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'username' => 'site-admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    public function test_guest_cannot_open_user_admin(): void
    {
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_regular_user_cannot_open_or_create_users_or_manage_telegram_binding(): void
    {
        $user = User::query()->create([
            'username' => 'regular-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $this->actingAs($user)->get('/admin/users')->assertForbidden();

        $this->actingAs($user)->post('/admin/users', [
            'username' => 'another-user',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->actingAs($user)
            ->post('/admin/users/'.$user->id.'/telegram-invite')
            ->assertForbidden();

        $this->actingAs($user)
            ->delete('/admin/users/'.$user->id.'/telegram-binding')
            ->assertForbidden();
    }

    public function test_admin_can_open_user_admin(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AdminUsers')
                ->has('users')
                ->has('telegramBot')
            );
    }

    public function test_admin_can_create_user_with_preset_password(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', [
                'username' => 'new-reader',
                'password' => 'initial-password-123',
                'password_confirmation' => 'initial-password-123',
            ])
            ->assertRedirect();

        $user = User::query()->where('username', 'new-reader')->firstOrFail();

        $this->assertSame('user', $user->role);
        $this->assertTrue(Hash::check('initial-password-123', $user->password));
        $this->assertNotSame('initial-password-123', $user->password);
    }

    public function test_admin_cannot_create_duplicate_username(): void
    {
        $admin = $this->admin();

        User::query()->create([
            'username' => 'existing-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $this->actingAs($admin)
            ->from('/admin/users')
            ->post('/admin/users', [
                'username' => 'existing-user',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHasErrors('username');
    }

    public function test_admin_can_generate_one_time_telegram_code_without_storing_plaintext(): void
    {
        $admin = $this->admin();
        $user = User::query()->create([
            'username' => 'telegram-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $response = $this->actingAs($admin)
            ->post('/admin/users/'.$user->id.'/telegram-invite')
            ->assertRedirect()
            ->assertSessionHas('telegram_invite');

        $flash = $this->app['session']->get('telegram_invite');

        $this->assertSame($user->id, $flash['user_id']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $flash['code']);

        $invite = TelegramInvite::query()->firstOrFail();

        $this->assertSame(hash('sha256', $flash['code']), $invite->code_hash);
        $this->assertNotSame($flash['code'], $invite->code_hash);
        $this->assertNull($invite->used_at);
    }

    public function test_new_telegram_code_invalidates_previous_unused_code(): void
    {
        $admin = $this->admin();
        $user = User::query()->create([
            'username' => 'telegram-reissue',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $this->actingAs($admin)->post('/admin/users/'.$user->id.'/telegram-invite');
        $firstHash = TelegramInvite::query()->value('code_hash');

        $this->actingAs($admin)->post('/admin/users/'.$user->id.'/telegram-invite');
        $secondHash = TelegramInvite::query()->value('code_hash');

        $this->assertDatabaseCount('telegram_invites', 1);
        $this->assertNotSame($firstHash, $secondHash);
    }

    public function test_admin_can_unlink_telegram_account(): void
    {
        $admin = $this->admin();
        $user = User::query()->create([
            'username' => 'telegram-linked',
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

        $this->actingAs($admin)
            ->delete('/admin/users/'.$user->id.'/telegram-binding')
            ->assertRedirect();

        $this->assertDatabaseMissing('user_telegram_accounts', [
            'user_id' => $user->id,
        ]);
    }
}
