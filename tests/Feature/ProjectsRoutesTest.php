<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectsRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'username' => 'projects-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);
    }

    public function test_guest_is_redirected_from_projects_catalog(): void
    {
        $this->get('/projects')->assertRedirect('/login');
    }

    public function test_guest_is_redirected_from_every_project(): void
    {
        foreach ([
            '/projects/bmp-to-mip',
            '/projects/pushkin-fairytales',
            '/projects/reading-diary',
            '/projects/cio-presentations',
            '/projects/kommersant-ranking',
            '/projects/vacancy-source',
        ] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_authenticated_user_can_open_projects_catalog_and_projects(): void
    {
        $this->actingAs($this->user());

        foreach ([
            '/projects',
            '/projects/bmp-to-mip',
            '/projects/pushkin-fairytales',
            '/projects/reading-diary',
            '/projects/cio-presentations',
            '/projects/kommersant-ranking',
            '/projects/vacancy-source',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
