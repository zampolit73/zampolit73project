<?php

namespace Tests\Feature;

use App\Models\PresentationSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CioPresentationsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'username' => 'cio-admin',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    public function test_guest_is_redirected_from_cio_project(): void
    {
        $this->get('/projects/cio-presentations')->assertRedirect('/login');
    }

    public function test_regular_user_cannot_open_cio_project(): void
    {
        $user = User::query()->create([
            'username' => 'regular-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $this->actingAs($user)->get('/projects/cio-presentations')->assertForbidden();
    }

    public function test_starter_sources_are_preinstalled(): void
    {
        $this->assertDatabaseHas('presentation_sources', [
            'name' => '1С:ERP 2025 — программа и материалы',
            'url' => 'https://1c.ru/bf/2025/default.jsp',
            'priority' => 99,
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'name' => 'CNews — ИТ-директор ОСК и ИИ',
            'url' => 'https://www.cnews.ru/news/top/2026-07-03_it-direktor_osk_rasskazal',
        ]);
    }

    public function test_admin_can_open_cio_project(): void
    {
        $sourceCount = PresentationSource::query()->count();

        $this->actingAs($this->admin())
            ->get('/projects/cio-presentations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CioPresentations')
                ->where('stats.total', 0)
                ->where('stats.sources', $sourceCount)
            );
    }

    public function test_admin_can_add_source_and_scan_direct_presentation_links(): void
    {
        Http::fake([
            'https://conference.example/materials' => Http::response(
                '<html><body><a href="/files/cio-2026.pdf">Доклад CIO 2026</a></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://conference.example/sitemap.xml' => Http::response('', 404),
        ]);

        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/projects/cio-presentations/sources', [
                'name' => 'Conference',
                'url' => 'https://conference.example/materials',
            ])
            ->assertRedirect();

        $source = PresentationSource::query()
            ->where('url', 'https://conference.example/materials')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post('/projects/cio-presentations/sources/'.$source->id.'/scan')
            ->assertRedirect();

        $this->assertDatabaseHas('presentations', [
            'source_id' => $source->id,
            'title' => 'Доклад CIO 2026',
            'file_type' => 'pdf',
            'file_url' => 'https://conference.example/files/cio-2026.pdf',
            'review_status' => 'new',
        ]);
    }
}
