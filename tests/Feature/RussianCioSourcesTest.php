<?php

namespace Tests\Feature;

use App\Models\Presentation;
use App\Models\PresentationSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RussianCioSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'username' => 'sources-admin',
            'password' => 'test-password',
            'role' => 'admin',
        ]);
    }

    public function test_source_migration_adds_archives_without_changing_existing_data(): void
    {
        $urls = [
            'https://okit2026.4cio.ru/report',
            'https://okit2025.4cio.ru/report',
            'https://okit2024.4cio.ru/report',
            'https://xn--90ard6a.xn--80agbpbtv1a.xn--p1ai/kc2025',
            'https://cnewsforum.ru/cases/2026/presentations',
            'https://cnewsforum.ru/cases/2025/presentations',
            'https://cnewsforum.ru/cases/2024/presentations',
        ];

        // Simulate an upgrade with one of the new URLs already managed by a user.
        PresentationSource::query()->whereIn('url', array_slice($urls, 1))->delete();
        $existing = PresentationSource::query()->where('url', $urls[0])->firstOrFail();
        $existing->update([
            'name' => 'Мой архив ОКИТ',
            'priority' => 42,
            'is_active' => false,
            'last_scanned_at' => now(),
            'last_scan_found' => 3,
            'last_error' => 'Previous scan error',
        ]);
        PresentationSource::query()->create([
            'name' => 'Ручной источник',
            'url' => 'https://manual.example/materials',
            'domain' => 'manual.example',
        ]);
        $presentation = Presentation::query()->create([
            'source_id' => $existing->id,
            'title' => 'Проверенный доклад',
            'file_type' => 'pdf',
            'file_url' => 'https://conference.example/checked.pdf',
            'review_status' => 'verified',
            'has_email' => true,
            'is_good_lead' => true,
        ]);
        $sourcesBefore = PresentationSource::query()->orderBy('id')->get()->toArray();
        $presentationBefore = $presentation->toArray();

        $migration = require database_path('migrations/2026_09_22_120000_add_russian_cio_presentation_sources.php');
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertDatabaseCount('presentation_sources', count($sourcesBefore) + 6);
        foreach ($urls as $url) {
            $this->assertDatabaseHas('presentation_sources', ['url' => $url]);
        }
        $this->assertSame($sourcesBefore, PresentationSource::query()
            ->whereIn('id', array_column($sourcesBefore, 'id'))->orderBy('id')->get()->toArray());
        $this->assertSame($presentationBefore, $presentation->fresh()->toArray());
    }

    public function test_scan_collects_archive_files_and_labelled_shares_without_requesting_them(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://conference.example/materials' => Http::response(<<<'HTML'
                <a href="/content/okit2026/pres/cio.pdf">Презентация ИТ-директора</a>
                <a href="/content/okit2025/architecture.pptx">Архитектура ИТ</a>
                <div class="presentations__item">
                    <a class="presentations__link" href="https://files.example/deck.pdf"></a>
                    <div class="presentations__company">Стратегия ИТ</div>
                    <div class="presentations-person__name">Иван Петров</div>
                    <div class="presentations-person__position">ИТ-директор, Компания</div>
                </div>
                <a href="https://disk.yandex.ru/i/Slides_1#viewer">Презентация</a>
                <a href="https://disk.yandex.ru/i/Slides_1">Презентация</a>
                <a href="https://disk.yandex.com/d/Slides-2">Slides</a>
                <a href="https://yadi.sk/i/Slides3">Слайды</a>
                <a href="https://disk.yandex.ru/i/Video">Видео</a>
                <a href="https://disk.yandex.ru/d/Photos">Фотографии</a>
                <a href="https://disk.yandex.ru/i/Unlabelled"></a>
                <a href="https://disk.yandex.ru.evil.example/i/Fake">Презентация</a>
                <a href="https://disk.yandex.ru/client/disk">Презентация</a>
                <a href="https://user:password@disk.yandex.ru/i/Auth">Презентация</a>
                <a href="https://disk.yandex.ru:8443/i/Port">Презентация</a>
                HTML, 200, ['Content-Type' => 'text/html']),
            'https://conference.example/sitemap.xml' => Http::response('', 404),
        ]);
        $source = PresentationSource::query()->create([
            'name' => 'Материалы конференции',
            'url' => 'https://conference.example/materials',
            'domain' => 'conference.example',
        ]);
        $this->actingAs($this->admin());

        $this->post('/projects/cio-presentations/sources/'.$source->id.'/scan')
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertDatabaseCount('presentations', 6);
        $this->assertDatabaseHas('presentations', [
            'file_url' => 'https://conference.example/content/okit2026/pres/cio.pdf',
            'file_type' => 'pdf',
            'title' => 'Презентация ИТ-директора',
        ]);
        $this->assertDatabaseHas('presentations', [
            'file_url' => 'https://conference.example/content/okit2025/architecture.pptx',
            'file_type' => 'pptx',
        ]);
        $this->assertDatabaseHas('presentations', [
            'file_url' => 'https://files.example/deck.pdf',
            'title' => 'Стратегия ИТ Иван Петров ИТ-директор, Компания',
        ]);
        foreach (['https://disk.yandex.ru/i/Slides_1', 'https://disk.yandex.com/d/Slides-2', 'https://yadi.sk/i/Slides3'] as $url) {
            $this->assertDatabaseHas('presentations', [
                'file_url' => $url,
                'file_type' => 'link',
                'source_id' => $source->id,
                'source_page_url' => $source->url,
                'review_status' => 'new',
            ]);
        }
        Http::assertSentCount(2);

        // Rescanning must preserve reviews and avoid duplicates, including fragments.
        $reviewed = Presentation::query()->where('file_type', 'link')->firstOrFail();
        $reviewed->update(['review_status' => 'verified', 'has_email' => true]);
        $this->post('/projects/cio-presentations/sources/'.$source->id.'/scan')
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('presentations', 6);
        $this->assertSame(0, $source->fresh()->last_scan_found);
        $this->assertSame('verified', $reviewed->fresh()->review_status);
        $this->assertTrue($reviewed->fresh()->has_email);
        Http::assertSentCount(4);
    }

    public function test_admin_can_add_and_filter_a_share_with_unknown_file_format(): void
    {
        Http::fake();
        $this->actingAs($this->admin());
        $this->post('/projects/cio-presentations/presentations', [
            'title' => 'Презентация на Яндекс Диске',
            'file_url' => 'https://disk.yandex.ru/i/ManualSlides',
            'file_type' => 'link',
        ])->assertSessionHasNoErrors()->assertRedirect();
        Presentation::query()->create([
            'title' => 'Другой доклад',
            'file_type' => 'pdf',
            'file_url' => 'https://conference.example/other.pdf',
        ]);

        $this->get('/projects/cio-presentations?tab=presentations&file_type=link')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('CioPresentations')
                ->where('filters.file_type', 'link')
                ->has('presentations.data', 1)
                ->where('presentations.data.0.file_url', 'https://disk.yandex.ru/i/ManualSlides')
                ->where('presentations.data.0.file_type', 'link')
            );
        Http::assertNothingSent();
    }
}
