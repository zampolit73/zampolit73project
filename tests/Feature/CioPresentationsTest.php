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

    public function test_regular_user_has_full_cio_project_access(): void
    {
        $user = User::query()->create([
            'username' => 'regular-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        Http::fake([
            'https://user-source.example/materials' => Http::response(
                '<html><body><a href="/files/user-presentation.pdf">Доклад пользователя</a></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://user-source.example/sitemap.xml' => Http::response('', 404),
        ]);

        $this->actingAs($user)
            ->get('/projects/cio-presentations?tab=sources')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CioPresentations')
                ->where('canManage', true)
                ->where('tab', 'sources')
            );

        $this->actingAs($user)
            ->post('/projects/cio-presentations/sources', [
                'name' => 'User source',
                'url' => 'https://user-source.example/materials',
            ])
            ->assertRedirect();

        $source = PresentationSource::query()
            ->where('url', 'https://user-source.example/materials')
            ->firstOrFail();

        $this->actingAs($user)
            ->post('/projects/cio-presentations/sources/'.$source->id.'/scan')
            ->assertRedirect();

        $presentation = Presentation::query()
            ->where('file_url', 'https://user-source.example/files/user-presentation.pdf')
            ->firstOrFail();

        $this->actingAs($user)
            ->patch('/projects/cio-presentations/presentations/'.$presentation->id, [
                'review_status' => 'verified',
                'is_good_lead' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('presentations', [
            'id' => $presentation->id,
            'review_status' => 'verified',
            'is_good_lead' => true,
        ]);
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
                ->where('canManage', true)
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
    public function test_user_can_take_review_and_release_a_presentation(): void
    {
        $user = User::query()->create([
            'username' => 'worker-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $presentation = Presentation::query()->create([
            'title' => 'Assignment test',
            'file_type' => 'pdf',
            'file_url' => 'https://example.com/assignment-test.pdf',
            'review_status' => 'new',
            'link_status' => 'unknown',
            'discovered_at' => now(),
        ]);

        $this->actingAs($user)
            ->patch('/projects/cio-presentations/presentations/'.$presentation->id, [
                'assignment_action' => 'take',
            ])
            ->assertRedirect();

        $presentation->refresh();

        $this->assertSame($user->id, $presentation->assigned_to_user_id);
        $this->assertNotNull($presentation->assigned_at);

        $assignedAt = $presentation->assigned_at?->toISOString();

        $this->actingAs($user)
            ->patch('/projects/cio-presentations/presentations/'.$presentation->id, [
                'review_status' => 'verified',
            ])
            ->assertRedirect();

        $presentation->refresh();

        $this->assertSame($user->id, $presentation->assigned_to_user_id);
        $this->assertSame($assignedAt, $presentation->assigned_at?->toISOString());
        $this->assertSame('verified', $presentation->review_status);

        $this->actingAs($user)
            ->patch('/projects/cio-presentations/presentations/'.$presentation->id, [
                'assignment_action' => 'release',
            ])
            ->assertRedirect();

        $presentation->refresh();

        $this->assertNull($presentation->assigned_to_user_id);
        $this->assertNull($presentation->assigned_at);
    }

    public function test_user_cannot_take_or_release_another_users_presentation(): void
    {
        $owner = User::query()->create([
            'username' => 'owner-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $other = User::query()->create([
            'username' => 'other-user',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $presentation = Presentation::query()->create([
            'title' => 'Occupied presentation',
            'file_type' => 'pdf',
            'file_url' => 'https://example.com/occupied.pdf',
            'review_status' => 'new',
            'link_status' => 'unknown',
            'assigned_to_user_id' => $owner->id,
            'assigned_at' => now(),
            'discovered_at' => now(),
        ]);

        $this->actingAs($other)
            ->from('/projects/cio-presentations?tab=presentations')
            ->patch('/projects/cio-presentations/presentations/'.$presentation->id, [
                'assignment_action' => 'take',
            ])
            ->assertRedirect('/projects/cio-presentations?tab=presentations')
            ->assertSessionHasErrors('assignment');

        $this->actingAs($other)
            ->from('/projects/cio-presentations?tab=presentations')
            ->patch('/projects/cio-presentations/presentations/'.$presentation->id, [
                'assignment_action' => 'release',
            ])
            ->assertRedirect('/projects/cio-presentations?tab=presentations')
            ->assertSessionHasErrors('assignment');

        $this->assertDatabaseHas('presentations', [
            'id' => $presentation->id,
            'assigned_to_user_id' => $owner->id,
        ]);
    }

    public function test_assignment_filter_supports_mine_free_and_specific_user(): void
    {
        $me = User::query()->create([
            'username' => 'filter-me',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        $other = User::query()->create([
            'username' => 'filter-other',
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);

        Presentation::query()->create([
            'title' => 'Mine',
            'file_type' => 'pdf',
            'file_url' => 'https://example.com/mine.pdf',
            'review_status' => 'new',
            'link_status' => 'unknown',
            'assigned_to_user_id' => $me->id,
            'assigned_at' => now(),
            'discovered_at' => now(),
        ]);

        Presentation::query()->create([
            'title' => 'Other',
            'file_type' => 'pdf',
            'file_url' => 'https://example.com/other.pdf',
            'review_status' => 'new',
            'link_status' => 'unknown',
            'assigned_to_user_id' => $other->id,
            'assigned_at' => now(),
            'discovered_at' => now(),
        ]);

        Presentation::query()->create([
            'title' => 'Free',
            'file_type' => 'pdf',
            'file_url' => 'https://example.com/free.pdf',
            'review_status' => 'new',
            'link_status' => 'unknown',
            'discovered_at' => now(),
        ]);

        $this->actingAs($me)
            ->get('/projects/cio-presentations?tab=presentations&assignee=mine')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.assignee', 'mine')
                ->where('stats.inWork', 2)
                ->where('stats.mine', 1)
                ->has('presentations.data', 1)
                ->where('presentations.data.0.title', 'Mine')
                ->has('assignees', 2)
            );

        $this->actingAs($me)
            ->get('/projects/cio-presentations?tab=presentations&assignee=unassigned')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('presentations.data', 1)
                ->where('presentations.data.0.title', 'Free')
            );

        $this->actingAs($me)
            ->get('/projects/cio-presentations?tab=presentations&assignee=user:'.$other->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('presentations.data', 1)
                ->where('presentations.data.0.title', 'Other')
            );
    }

}
