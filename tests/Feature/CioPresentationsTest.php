<?php

namespace Tests\Feature;

use App\Models\Presentation;
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

    public function test_starter_sources_use_only_approved_families_and_exclude_rejected_presets(): void
    {
        $this->assertDatabaseMissing('presentation_sources', [
            'url' => 'https://1c.ru/bf/2025/default.jsp',
        ]);

        $this->assertDatabaseMissing('presentation_sources', [
            'url' => 'https://ru.globalcio.ru/pharma26_dm1',
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'name' => 'TAdviser SummIT — архив и планы 2025',
            'url' => 'https://summit.tadviser.ru/a/2024-2/',
            'priority' => 100,
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'name' => 'CNews — индекс материалов CIO / ИТ-директор',
            'url' => 'https://www.cnews.ru/book/mutual/1667/2195',
            'priority' => 100,
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'url' => 'https://cnewsforum.ru/cases/presentations',
            'priority' => 130,
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'url' => 'https://industrialconf.ru/2025/abstracts',
            'priority' => 126,
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'url' => 'https://cipr-reports.ru/',
            'priority' => 130,
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'url' => 'https://tsups.ib-bank.ru/materials',
            'priority' => 124,
        ]);
    }

    public function test_2026_tadviser_and_cnews_sources_are_preinstalled(): void
    {
        $this->assertDatabaseHas('presentation_sources', [
            'url' => 'https://tadvisersummit.ru/a/2026-1/',
            'priority' => 120,
        ]);

        $this->assertDatabaseHas('presentation_sources', [
            'url' => 'https://www.cnews.ru/news/top/2026-09-16_sotni_it-direktorov_rossii',
            'priority' => 120,
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

    public function test_admin_can_clear_presentations_and_scan_history_without_deleting_sources(): void
    {
        $admin = $this->admin();
        $source = PresentationSource::query()->firstOrFail();

        $source->update([
            'last_scanned_at' => now(),
            'last_scan_found' => 4,
            'last_error' => 'old error',
        ]);

        Presentation::query()->create([
            'source_id' => $source->id,
            'title' => 'Old presentation',
            'file_type' => 'pdf',
            'file_url' => 'https://example.com/old.pdf',
            'review_status' => 'verified',
            'link_status' => 'working',
            'has_email' => true,
            'has_phone' => true,
            'is_good_lead' => true,
            'discovered_at' => now(),
            'reviewed_at' => now(),
        ]);

        $sourceCount = PresentationSource::query()->count();

        $this->actingAs($admin)
            ->delete('/projects/cio-presentations/presentations')
            ->assertRedirect();

        $this->assertDatabaseCount('presentations', 0);
        $this->assertDatabaseCount('presentation_sources', $sourceCount);
        $this->assertDatabaseHas('presentation_sources', [
            'id' => $source->id,
            'last_scanned_at' => null,
            'last_scan_found' => 0,
            'last_error' => null,
        ]);
    }

    public function test_scanner_follows_a_bounded_relevant_internal_page_to_find_presentations(): void
    {
        Http::fake([
            'https://industrial.example/2025/abstracts' => Http::response(
                '<html><body>'
                .'<a href="/2025/abstracts/42">Доклад: цифровое производство</a>'
                .'<a href="/register">Регистрация</a>'
                .'<a href="https://outside.example/materials">Внешние материалы</a>'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://industrial.example/2025/abstracts/42' => Http::response(
                '<html><body><a href="/files/cio-industrial.pdf">Скачать презентацию</a></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://industrial.example/sitemap.xml' => Http::response('', 404),
        ]);

        $source = PresentationSource::query()->create([
            'name' => 'Industrial test',
            'url' => 'https://industrial.example/2025/abstracts',
            'domain' => 'industrial.example',
            'priority' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post('/projects/cio-presentations/sources/'.$source->id.'/scan')
            ->assertRedirect();

        $this->assertDatabaseHas('presentations', [
            'source_id' => $source->id,
            'file_url' => 'https://industrial.example/files/cio-industrial.pdf',
            'file_type' => 'pdf',
            'source_page_url' => 'https://industrial.example/2025/abstracts/42',
        ]);

        Http::assertNotSent(
            fn ($request) => str_contains($request->url(), '/register')
                || str_contains($request->url(), 'outside.example')
        );
    }

    public function test_scan_http_failure_returns_validation_error_instead_of_server_error(): void
    {
        Http::fake([
            'https://broken.example/materials' => Http::response('upstream failure', 503),
        ]);

        $source = PresentationSource::query()->create([
            'name' => 'Broken source',
            'url' => 'https://broken.example/materials',
            'domain' => 'broken.example',
            'priority' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->from('/projects/cio-presentations')
            ->post('/projects/cio-presentations/sources/'.$source->id.'/scan')
            ->assertRedirect('/projects/cio-presentations')
            ->assertSessionHasErrors('scan');

        $source->refresh();

        $this->assertSame(0, $source->last_scan_found);
        $this->assertStringContainsString('HTTP 503', $source->last_error);
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
