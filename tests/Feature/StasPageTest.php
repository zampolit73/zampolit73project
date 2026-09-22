<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StasPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_stas_page_is_admin_only(): void
    {
        $this->get('/stas')->assertRedirect('/login');

        $user = User::query()->create([
            'username' => 'stas-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $this->actingAs($user)->get('/stas')->assertForbidden();

        $admin = User::query()->create([
            'username' => 'stas-admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get('/stas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Stas'));
    }
}
