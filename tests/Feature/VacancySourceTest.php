<?php

namespace Tests\Feature;

use App\Jobs\RunVacancyInvestigation;
use App\Models\InvestigationCandidate;
use App\Models\InvestigationSource;
use App\Models\User;
use App\Models\VacancyInvestigation;
use App\Services\BingRssSearchProvider;
use App\Services\HabrCareerResearchService;
use App\Services\TelegramBotClient;
use App\Services\TelegramReaderClient;
use App\Services\VacancyCombinedResearchService;
use App\Services\VacancySignalExtractor;
use App\Services\VacancyTelegramResultFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
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
            'https://career.habr.com/*' => Http::response('', 404),
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
            app(VacancyCombinedResearchService::class),
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

    public function test_job_completes_with_telegram_client_and_habr_publisher_and_links_alias_evidence(): void
    {
        $user = $this->user('relation-finalization-user');
        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => 'Frontend developer JavaScript React HTML CSS.',
            'status' => 'queued',
            'progress_stage' => 'queued',
            'queued_at' => now(),
        ]);

        $research = Mockery::mock(VacancyCombinedResearchService::class);
        $research->shouldReceive('research')
            ->once()
            ->andReturn([
                'signals' => [
                    'normalized_text' => 'frontend developer javascript react html css',
                    'fingerprint' => str_repeat('a', 64),
                ],
                'sources' => [
                    [
                        'provider' => 'telegram_reader',
                        'title' => 'Telegram · T-Bank IT Partnership',
                        'url' => 'telegram://message/-1001/11',
                        'snippet' => 'Frontend developer JavaScript React HTML CSS.',
                        'search_query' => 'frontend react',
                        'evidence_score' => 95,
                        'candidate_name' => 'T-Bank',
                    ],
                    [
                        'provider' => 'habr_career',
                        'title' => 'Стажёр-фронтенд разработчик',
                        'url' => 'https://career.habr.com/vacancies/1000168347',
                        'snippet' => 'JavaScript React HTML CSS.',
                        'search_query' => 'Habr skill: JavaScript',
                        'evidence_score' => 55,
                        'candidate_name' => 'Лоция',
                    ],
                ],
                'candidates' => [
                    [
                        'company_name' => 'Т-Банк',
                        'candidate_type' => 'provenance',
                        'confidence' => 72,
                        'is_end_client' => true,
                        'explanation' => 'Telegram provenance.',
                    ],
                    [
                        'company_name' => 'Лоция',
                        'candidate_type' => 'publisher',
                        'confidence' => 55,
                        'is_end_client' => false,
                        'explanation' => 'Habr publisher.',
                    ],
                ],
                'summary' => 'Вероятный конечный клиент: Т-Банк — 72%.',
                'partial' => false,
            ]);

        (new RunVacancyInvestigation($investigation->id))->handle(
            app(TelegramBotClient::class),
            $research,
            app(VacancySignalExtractor::class),
            app(VacancyTelegramResultFormatter::class),
        );

        $investigation->refresh();

        $this->assertSame('completed', $investigation->status);
        $this->assertSame('completed', $investigation->progress_stage);

        $tbank = InvestigationCandidate::query()
            ->where('investigation_id', $investigation->id)
            ->where('company_name', 'Т-Банк')
            ->firstOrFail();
        $loodsen = InvestigationCandidate::query()
            ->where('investigation_id', $investigation->id)
            ->where('company_name', 'Лоция')
            ->firstOrFail();

        $this->assertTrue($tbank->is_end_client);
        $this->assertFalse($loodsen->is_end_client);
        $this->assertSame('publisher', $loodsen->candidate_type);

        $telegramSource = InvestigationSource::query()
            ->where('investigation_id', $investigation->id)
            ->where('provider', 'telegram_reader')
            ->firstOrFail();

        $this->assertSame($tbank->id, $telegramSource->candidate_id);
    }

    public function test_habr_career_provider_fetches_full_vacancy_and_structured_employer(): void
    {
        $input = implode("\n", [
            'Backend Java developer',
            'Требования:',
            '— Java, Spring Boot, Kafka, PostgreSQL',
            '— Опыт проектирования микросервисной архитектуры',
            '— Интеграции через REST API',
        ]);

        $search = Mockery::mock(BingRssSearchProvider::class);
        $search->shouldReceive('search')
            ->atLeast()
            ->once()
            ->andReturn([]);

        Http::fake([
            'https://career.habr.com/vacancies/skills/*' => Http::response(
                '<html><body><a href="/vacancies/1000999999">Backend Java developer</a></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://career.habr.com/vacancies/1000999999' => Http::response(<<<'HTML'
<!doctype html>
<html lang="ru">
<head>
  <title>Вакансия «Backend Java developer» в Москве, работа в компании «Acme Bank» — Хабр Карьера</title>
</head>
<body>
  <h1>Backend Java developer</h1>
  <h2>Компания</h2>
  <div>
    <a href="/companies/acme-bank">Acme Bank</a>
    <p>Продуктовый банк и финтех-компания</p>
  </div>
  <h2>Описание вакансии</h2>
  <div>
    Java, Spring Boot, Apache Kafka, PostgreSQL.
    Опыт проектирования микросервисной архитектуры.
    Интеграции через REST API.
  </div>
</body>
</html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $service = new HabrCareerResearchService(
            app(VacancySignalExtractor::class),
            $search,
        );

        $result = $service->research($input);

        $this->assertFalse($result['partial']);
        $this->assertCount(1, $result['sources']);
        $this->assertSame('habr_career', $result['sources'][0]['provider']);
        $this->assertSame('Acme Bank', $result['sources'][0]['candidate_name']);
        $this->assertGreaterThanOrEqual(60, $result['sources'][0]['evidence_score']);
        $this->assertSame('Acme Bank', $result['candidates'][0]['company_name']);
        $this->assertFalse($result['candidates'][0]['is_end_client']);
        $this->assertSame('publisher', $result['candidates'][0]['candidate_type']);
        $this->assertLessThanOrEqual(3, config('vacancy_source.habr.max_pages'));
        $this->assertLessThanOrEqual(6, config('vacancy_source.habr.request_timeout'));
    }

    public function test_telegram_corpus_hit_is_deduplicated_and_persisted_as_evidence(): void
    {
        Http::fake([
            'https://www.bing.com/search*' => Http::response(
                '<?xml version="1.0"?><rss version="2.0"><channel><title>Bing</title></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
            'https://career.habr.com/*' => Http::response('', 404),
        ]);

        $vacancy = implode("\n", [
            'Backend Java developer',
            'Заказчик: Acme Bank',
            'Требования: Java, Kafka, Camunda, PostgreSQL, микросервисы.',
            'Нужен опыт интеграций и проектирования распределённых систем.',
        ]);

        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('search')
            ->once()
            ->andReturn([
                [
                    'peer_id' => -1001234567890,
                    'message_id' => 501,
                    'message_date' => '2026-10-01T10:00:00+00:00',
                    'text' => $vacancy,
                    'source_link' => 'https://t.me/acme_jobs/501',
                    'chat_title' => 'Партнёрский канал',
                    'rank' => -8.1,
                ],
                [
                    'peer_id' => -1009876543210,
                    'message_id' => 902,
                    'message_date' => '2026-10-01T10:05:00+00:00',
                    'text' => $vacancy,
                    'source_link' => null,
                    'chat_title' => 'Репост вакансий',
                    'rank' => -7.9,
                ],
            ]);

        $this->app->instance(TelegramReaderClient::class, $reader);

        $user = $this->user('telegram-research-user');
        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => $vacancy,
            'status' => 'queued',
            'progress_stage' => 'queued',
            'queued_at' => now(),
        ]);

        (new RunVacancyInvestigation($investigation->id))->handle(
            app(TelegramBotClient::class),
            app(VacancyCombinedResearchService::class),
            app(VacancySignalExtractor::class),
            app(VacancyTelegramResultFormatter::class),
        );

        $investigation->refresh();

        $this->assertSame('completed', $investigation->status);
        $this->assertStringContainsString('Acme Bank', (string) $investigation->result_summary);
        $this->assertStringContainsString('источником: Telegram', (string) $investigation->result_summary);

        $candidate = InvestigationCandidate::query()->firstOrFail();
        $this->assertSame('Acme Bank', $candidate->company_name);
        $this->assertGreaterThanOrEqual(60, $candidate->confidence);

        $sources = InvestigationSource::query()->get();
        $this->assertCount(1, $sources);
        $this->assertSame('telegram_reader', $sources->first()->provider);
        $this->assertSame('https://t.me/acme_jobs/501', $sources->first()->url);
    }

    public function test_weak_habr_match_is_kept_as_hypothesis_below_strong_threshold(): void
    {
        $input = 'Backend Java developer. Java, Kafka, PostgreSQL, микросервисы.';

        $search = Mockery::mock(BingRssSearchProvider::class);
        $search->shouldReceive('search')->andReturn([]);

        Http::fake([
            'https://career.habr.com/vacancies/skills/*' => Http::response(
                '<html><body><a href="/vacancies/1000888888">Java developer</a></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://career.habr.com/vacancies/1000888888' => Http::response(<<<'HTML'
<html><head><title>Вакансия «Backend Java developer» в компании «Example Bank» — Хабр Карьера</title></head>
<body>
<h1>Backend Java developer</h1>
<a href="/companies/example-bank">Example Bank</a>
<h2>Описание вакансии</h2>
<div>Backend Java, Kafka, PostgreSQL, микросервисная архитектура.</div>
</body></html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $service = new HabrCareerResearchService(app(VacancySignalExtractor::class), $search);
        $result = $service->research($input);

        $this->assertNotEmpty($result['sources']);
        $this->assertNotEmpty($result['candidates']);
        $this->assertSame('Example Bank', $result['candidates'][0]['company_name']);
        $this->assertGreaterThanOrEqual(40, $result['candidates'][0]['confidence']);
        $this->assertFalse($result['candidates'][0]['is_end_client']);
        $this->assertSame('publisher', $result['candidates'][0]['candidate_type']);
    }

    public function test_telegram_display_collapses_multiple_matches_from_same_chat(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('search')->once()->andReturn([
            [
                'peer_id' => -100111,
                'message_id' => 1,
                'text' => 'Заказчик: Acme Bank. Backend Java Kafka PostgreSQL микросервисы.',
                'source_link' => 'https://t.me/acme/1',
                'chat_title' => 'T-Bank IT Partnership',
            ],
            [
                'peer_id' => -100111,
                'message_id' => 2,
                'text' => 'Заказчик: Acme Bank. Backend Java Kafka PostgreSQL микросервисы. Срочно.',
                'source_link' => 'https://t.me/acme/2',
                'chat_title' => 'T-Bank IT Partnership',
            ],
        ]);
        $this->app->instance(TelegramReaderClient::class, $reader);

        $service = app(\App\Services\VacancyTelegramResearchService::class);
        $result = $service->research('Backend Java Kafka PostgreSQL микросервисы');

        $this->assertCount(1, $result['sources']);
        $this->assertStringContainsString('2 совпадений', $result['sources'][0]['title']);
    }

    public function test_partner_channel_can_supply_client_provenance_without_explicit_company_in_message(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('search')->once()->andReturn([
            [
                'peer_id' => -100777,
                'message_id' => 77,
                'text' => 'Frontend developer. JavaScript React HTML CSS. Разработка пользовательских интерфейсов.',
                'source_link' => null,
                'chat_title' => 'T-Bank IT Partnership',
            ],
        ]);
        $this->app->instance(TelegramReaderClient::class, $reader);

        $service = app(\App\Services\VacancyTelegramResearchService::class);
        $result = $service->research('Frontend developer JavaScript React HTML CSS пользовательские интерфейсы');

        $this->assertNotEmpty($result['candidates']);
        $this->assertSame('Т-Банк', $result['candidates'][0]['company_name']);
        $this->assertSame('provenance', $result['candidates'][0]['candidate_type']);
        $this->assertLessThanOrEqual(72, $result['candidates'][0]['confidence']);
        $this->assertStringContainsString('партнёрском Telegram-канале', $result['candidates'][0]['explanation']);
    }

    public function test_habr_publisher_can_be_promoted_to_end_client_when_independently_confirmed(): void
    {
        $combined = app(VacancyCombinedResearchService::class);

        $reflection = new \ReflectionClass($combined);
        $method = $reflection->getMethod('mergeCandidates');
        $method->setAccessible(true);

        $result = $method->invoke(
            $combined,
            [[
                'company_name' => 'Acme Bank',
                'candidate_type' => 'direct',
                'confidence' => 70,
                'is_end_client' => true,
                'explanation' => 'Web confirmation.',
                'source_urls' => ['https://example.com/acme'],
                'providers' => ['bing_rss'],
            ]],
            [[
                'company_name' => 'Acme Bank',
                'candidate_type' => 'publisher',
                'confidence' => 65,
                'is_end_client' => false,
                'explanation' => 'Habr publisher.',
                'source_urls' => ['https://career.habr.com/vacancies/1000'],
                'providers' => ['habr_career'],
            ]],
            [],
            [
                [
                    'provider' => 'bing_rss',
                    'candidate_name' => 'Acme Bank',
                    'url' => 'https://example.com/acme',
                    'evidence_score' => 70,
                ],
                [
                    'provider' => 'habr_career',
                    'candidate_name' => 'Acme Bank',
                    'url' => 'https://career.habr.com/vacancies/1000',
                    'evidence_score' => 65,
                ],
            ],
        );

        $this->assertNotEmpty($result);
        $this->assertTrue($result[0]['is_end_client']);
        $this->assertSame('direct', $result[0]['candidate_type']);
        $this->assertGreaterThan(70, $result[0]['confidence']);
    }

    public function test_habr_service_provider_is_classified_as_intermediary_not_end_client(): void
    {
        $input = 'Frontend developer JavaScript React HTML CSS пользовательские интерфейсы';

        $search = Mockery::mock(BingRssSearchProvider::class);
        $search->shouldReceive('search')->andReturn([]);

        Http::fake([
            'https://career.habr.com/vacancies/skills/*' => Http::response(
                '<html><body><a href="/vacancies/1000123456">Frontend developer</a></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'https://career.habr.com/vacancies/1000123456' => Http::response(<<<'HTML'
<html><head><title>Вакансия «Frontend developer» в компании «Лоция» — Хабр Карьера</title></head>
<body>
<h1>Frontend developer</h1>
<a href="/companies/loodsen">Лоция</a>
<h2>Описание вакансии</h2>
<div>JavaScript React HTML CSS. Разработка пользовательских интерфейсов.</div>
</body></html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'https://career.habr.com/companies/loodsen' => Http::response(
                '<html><body>Создаем ИТ-решения для бизнеса. Разрабатываем ПО и цифровые продукты для клиентов.</body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
        ]);

        $service = new HabrCareerResearchService(app(VacancySignalExtractor::class), $search);
        $result = $service->research($input);

        $this->assertNotEmpty($result['candidates']);
        $this->assertSame('Лоция', $result['candidates'][0]['company_name']);
        $this->assertFalse($result['candidates'][0]['is_end_client']);
        $this->assertSame('intermediary', $result['candidates'][0]['candidate_type']);
    }

    public function test_generic_outstaff_chat_title_is_not_used_as_client_provenance(): void
    {
        $reader = Mockery::mock(TelegramReaderClient::class);
        $reader->shouldReceive('search')->once()->andReturn([
            [
                'peer_id' => -100778,
                'message_id' => 78,
                'text' => 'Frontend developer. JavaScript React HTML CSS.',
                'source_link' => 'https://t.me/outstaff_requests_phpdev/78',
                'chat_title' => 'Аутстафф / Вакансии',
            ],
        ]);
        $this->app->instance(TelegramReaderClient::class, $reader);

        $service = app(\App\Services\VacancyTelegramResearchService::class);
        $result = $service->research('Frontend developer JavaScript React HTML CSS');

        $this->assertEmpty($result['candidates']);
    }

    public function test_web_research_does_not_invent_client_when_search_has_no_evidence(): void
    {
        Http::fake([
            'https://www.bing.com/search*' => Http::response(
                '<?xml version="1.0"?><rss version="2.0"><channel><title>Bing</title></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
            'https://career.habr.com/*' => Http::response('', 404),
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
            app(VacancyCombinedResearchService::class),
            app(VacancySignalExtractor::class),
            app(VacancyTelegramResultFormatter::class),
        );

        $investigation->refresh();

        $this->assertSame('completed', $investigation->status);
        $this->assertDatabaseCount('investigation_candidates', 0);
        $this->assertStringContainsString('Надёжный конечный клиент не определён', $investigation->result_summary);
    }
}
