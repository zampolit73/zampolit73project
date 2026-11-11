<?php

namespace Tests\Feature;

use App\Models\CompanyResearchImport;
use App\Models\InvestigationCandidate;
use App\Models\TelegramInvite;
use App\Models\VacancyInvestigation;
use App\Models\User;
use App\Models\UserTelegramAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
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
    public function test_regular_user_cannot_delete_another_user(): void
    {
        $user = User::query()->create([
            'username' => 'regular-deleting-user', 'password' => Hash::make('password'), 'role' => 'user',
        ]);
        $target = User::query()->create([
            'username' => 'target-forbidden', 'password' => Hash::make('password'), 'role' => 'user',
        ]);

        $this->actingAs($user)->delete('/admin/users/'.$target->id, [
            'confirmation_username' => $target->username,
        ])->assertForbidden();

        $this->assertModelExists($target);
    }

    public function test_admin_cannot_delete_self_or_other_administrators(): void
    {
        $admin = $this->admin();
        $otherAdmin = User::query()->create([
            'username' => 'second-admin', 'password' => Hash::make('password'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)->from('/admin/users')->delete('/admin/users/'.$admin->id, [
            'confirmation_username' => $admin->username,
        ])->assertSessionHasErrors('delete_user');

        $this->actingAs($admin)->from('/admin/users')->delete('/admin/users/'.$otherAdmin->id, [
            'confirmation_username' => $otherAdmin->username,
        ])->assertSessionHasErrors('delete_user');

        $this->assertModelExists($admin);
        $this->assertModelExists($otherAdmin);
    }

    public function test_deletion_requires_matching_username_and_cannot_happen_by_accident(): void
    {
        $admin = $this->admin();
        $user = User::query()->create([
            'username' => 'del-target', 'password' => Hash::make('password'), 'role' => 'user',
        ]);

        $this->actingAs($admin)->from('/admin/users')->delete('/admin/users/'.$user->id, [
            'confirmation_username' => 'someone-else',
        ])->assertSessionHasErrors('delete_user');

        $this->actingAs($admin)->from('/admin/users')->delete('/admin/users/'.$user->id)
            ->assertSessionHasErrors('confirmation_username');

        $this->assertModelExists($user);
    }

    public function test_admin_can_delete_employee_without_losing_research_and_vacancy_history(): void
    {
        $admin = $this->admin();
        $user = User::query()->create([
            'username' => 'departed-sales', 'password' => Hash::make('password'), 'role' => 'user',
        ]);

        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => 'Java developer',
            'status' => 'finished',
        ]);
        $candidate = InvestigationCandidate::query()->create([
            'investigation_id' => $investigation->id,
            'company_name' => 'Test purchaser',
            'candidate_type' => 'direct',
            'confidence' => 80,
            'is_end_client' => true,
            'rank' => 1,
        ]);
        $research = CompanyResearchImport::query()->create([
            'identifier' => 'inn-7707083893',
            'company_inn' => '7707083893',
            'company_name' => 'Test company',
            'payload' => '{"schema_version":1}',
            'payload_sha256' => str_repeat('a', 64),
            'revision' => 1,
            'imported_by' => $user->id,
        ]);
        $research->revisions()->create([
            'revision' => 1,
            'payload' => '{"schema_version":1}',
            'payload_sha256' => str_repeat('a', 64),
            'imported_by' => $user->id,
        ]);

        UserTelegramAccount::query()->create([
            'user_id' => $user->id,
            'telegram_user_id' => 888123,
            'telegram_chat_id' => 888123,
            'linked_at' => now(),
        ]);

        DB::table('sessions')->insert([
            'id' => 'old-user-session',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);
        DB::table('push_subscriptions')->insert([
            'user_id' => $user->id,
            'endpoint' => 'https://example.org/push/old',
            'public_key' => 'public-key',
            'auth_token' => 'auth-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->delete('/admin/users/'.$user->id, [
            'confirmation_username' => $user->username,
        ])->assertRedirect('/admin/users')->assertSessionHas('user_deleted', $user->username);

        $this->assertModelMissing($user);
        $this->assertDatabaseHas('vacancy_investigations', [
            'id' => $investigation->id,
            'user_id' => null,
        ]);
        $this->assertModelExists($candidate);
        $this->assertDatabaseHas('company_research_imports', [
            'id' => $research->id, 'imported_by' => null,
        ]);
        $this->assertDatabaseHas('company_research_import_revisions', [
            'research_import_id' => $research->id, 'imported_by' => null,
        ]);
        $this->assertDatabaseMissing('user_telegram_accounts', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-user-session']);
        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://example.org/push/old']);
    }

}
