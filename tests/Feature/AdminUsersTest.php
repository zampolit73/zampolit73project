<?php

namespace Tests\Feature;

use App\Models\User;
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

    public function test_regular_user_cannot_open_or_create_users(): void
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
    }

    public function test_admin_can_open_user_admin(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AdminUsers')
                ->has('users')
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
}
