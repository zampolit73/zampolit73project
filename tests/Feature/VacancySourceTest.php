<?php

namespace Tests\Feature;

use App\Jobs\RunVacancyInvestigation;
use App\Models\InvestigationCandidate;
use App\Models\InvestigationSource;
use App\Models\User;
use App\Models\VacancyInvestigation;
use App\Services\TelegramBotClient;
use App\Services\VacancySignalExtractor;
use App\Services\VacancyTelegramResultFormatter;
use App\Services\VacancyWebResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VacancySourceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username = 'vacancy-user', string $role = 'user'): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    public function test_guest_is_redirected_and_user_can_open_project(): void
    {
        $this->get('/projects/vacancy-source')->assertRedirect('/login');

        $this->actingAs($this->user())
            ->get('/projects/vacancy-source')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('VacancySource')
                ->where('isAdmin', false)
                ->has('history', 0)
                ->where('activeInvestigation', null)
            );
    }

    public function test_user_can_queue_investigation(): void
    {
        Queue::fake();

        $user = $this->user();

        $response = $this->actingAs($user)
            ->post('/projects/vacancy-source/investigations', [
                'input_text' => 'Senior Java developer: Kafka, Camunda, PostgreSQL, микросервисы и интеграции.',
            ]);

        $investigation = VacancyInvestigation::query()->firstOrFail();

        $response->assertRedirect('/projects/vacancy-source?active='.$investigation->id);

        $this->assertSame($user->id, $investigation->user_id);
        $this->assertSame('web', $investigation->input_source);
        $this->assertSame('queued', $investigation->status);
        $this->assertSame('queued', $investigation->progress_stage);

        Queue::assertPushedOn('vacancy-source', RunVacancyInvestigation::class);
    }

    public function test_user_cannot_see_another_users_investigation_status(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');

        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $owner->id,
            'input_source' => 'web',
            'input_text' => 'Backend developer vacancy with enough details for the test.',
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $this->actingAs($other)
            ->get('/projects/vacancy-source/investigations/'.$investigation->id.'/status')
            ->assertNotFound();
    }

    public function test_admin_can_see_team_history_while_user_sees_only_own(): void
    {
        $admin = $this->user('admin-vacancy', 'admin');
        $first = $this->user('first-vacancy');
        $second = $this->user('second-vacancy');

        foreach ([$first, $second] as $user) {
            VacancyInvestigation::query()->create([
                'user_id' => $user->id,
                'input_source' => 'web',
                'input_text' => 'Vacancy text for history visibility testing.',
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        }

        $this->actingAs($first)
            ->get('/projects/vacancy-source')
            ->assertInertia(fn (Assert $page) => $page->has('history', 1));

        $this->actingAs($admin)
            ->get('/projects/vacancy-source')
            ->assertInertia(fn (Assert $page) => $page
                ->where('isAdmin', true)
                ->has('history', 2)
            );
    }

    public function test_only_queued_investigation_can_be_cancelled(): void
    {
        $user = $this->user();

        $queued = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => 'Queued vacancy text with enough detail to exist.',
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        $this->actingAs($user)
            ->post('/projects/vacancy-source/investigations/'.$queued->id.'/cancel')
            ->assertRedirect('/projects/vacancy-source?active='.$queued->id);

        $queued->refresh();
        $this->assertSame('cancelled', $queued->status);
        $this->assertNotNull($queued->cancelled_at);

        $running = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => 'Running vacancy text with enough detail to exist.',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->actingAs($user)
            ->from('/projects/vacancy-source?active='.$running->id)
            ->post('/projects/vacancy-source/investigations/'.$running->id.'/cancel')
            ->assertRedirect('/projects/vacancy-source?active='.$running->id)
            ->assertSessionHasErrors('investigation');

        $this->assertSame('running', $running->fresh()->status);
    }

    public function test_real_web_research_job_persists_candidate_and_source(): void
    {
        Http::fake([
            'https://www.bing.com/search*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<rss version="2.0">
  <channel>
    <title>Bing</title>
    <item>
      <title>Вакансия Frontend JavaScript developer, работа в компании Acme Digital</title>
      <link>https://hh.ru/vacancy/123456</link>
      <description>Уверенное знание React и Redux. Опыт работы с Electron или желание разрабатывать desktop-приложения (важно). Уверенное знание HTTP протокола.</description>
      <pubDate>Thu, 24 Sep 2026 12:00:00 GMT</pubDate>
    </item>
  </channel>
</rss>
XML, 200, ['Content-Type' => 'application/rss+xml']),
        ]);

        $user = $this->user();

        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => implode("\n", [
                'Frontend JavaScript developer',
                'Требования:',
                '— Отличное знание JS (ES 6+)',
                '— Уверенное знание React и Redux',
                '— Опыт работы с Electron или желание разрабатывать desktop-приложения (важно)',
                '— Уверенное знание HTTP протокола',
            ]),
            'status' => 'queued',
            'progress_stage' => 'queued',
            'queued_at' => now(),
        ]);

        (new RunVacancyInvestigation($investigation->id))->handle(
            app(TelegramBotClient::class),
            app(VacancyWebResearchService::class),
            app(VacancySignalExtractor::class),
            app(VacancyTelegramResultFormatter::class),
        );

        $investigation->refresh();
        $candidate = InvestigationCandidate::query()->firstOrFail();
        $source = InvestigationSource::query()->firstOrFail();

        $this->assertSame('completed', $investigation->status);
        $this->assertSame('completed', $investigation->progress_stage);
        $this->assertSame('Готово', $investigation->progress_text);
        $this->assertNotNull($investigation->started_at);
        $this->assertNotNull($investigation->finished_at);
        $this->assertNotNull($investigation->normalized_text);
        $this->assertNotNull($investigation->fingerprint);
        $this->assertStringContainsString('Acme Digital', $investigation->result_summary);

        $this->assertSame('Acme Digital', $candidate->company_name);
        $this->assertTrue($candidate->is_end_client);
        $this->assertSame('direct', $candidate->candidate_type);
        $this->assertGreaterThanOrEqual(60, $candidate->confidence);

        $this->assertSame($investigation->id, $source->investigation_id);
        $this->assertSame($candidate->id, $source->candidate_id);
        $this->assertSame('bing_rss', $source->provider);
        $this->assertSame('https://hh.ru/vacancy/123456', $source->url);
        $this->assertGreaterThanOrEqual(60, $source->evidence_score);

        $this->actingAs($user)
            ->get('/projects/vacancy-source/investigations/'.$investigation->id.'/status')
            ->assertOk()
            ->assertJsonPath('candidates.0.company_name', 'Acme Digital')
            ->assertJsonPath('sources.0.url', 'https://hh.ru/vacancy/123456');
    }

    public function test_web_research_does_not_invent_client_when_search_has_no_evidence(): void
    {
        Http::fake([
            'https://www.bing.com/search*' => Http::response(
                '<?xml version="1.0"?><rss version="2.0"><channel><title>Bing</title></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
        ]);

        $user = $this->user();

        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => 'Редкая JavaScript вакансия React Redux Electron с проектированием сложных систем.',
            'status' => 'queued',
            'progress_stage' => 'queued',
            'queued_at' => now(),
        ]);

        (new RunVacancyInvestigation($investigation->id))->handle(
            app(TelegramBotClient::class),
            app(VacancyWebResearchService::class),
            app(VacancySignalExtractor::class),
            app(VacancyTelegramResultFormatter::class),
        );

        $investigation->refresh();

        $this->assertSame('completed', $investigation->status);
        $this->assertDatabaseCount('investigation_candidates', 0);
        $this->assertStringContainsString('Надёжный конечный клиент не определён', $investigation->result_summary);
    }
}
