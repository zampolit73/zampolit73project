<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_home(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_guest_can_view_login(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_guest_is_redirected_from_protected_design_system(): void
    {
        $this->get('/design-system')->assertRedirect('/login');
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::query()->create([
            'username' => 'tester',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $this->post('/login', ['username' => 'tester', 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
