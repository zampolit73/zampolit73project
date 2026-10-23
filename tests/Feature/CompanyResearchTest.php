<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CompanyResearchRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyResearchTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/irlix-reports-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->app->instance(CompanyResearchRepository::class, new CompanyResearchRepository($this->directory));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    private function user(string $role = 'admin'): User
    {
        return User::query()->create([
            'username' => 'research-user-'.$role,
            'role' => $role,
            'password' => Hash::make('test-password'),
        ]);
    }

    private function sample(): array
    {
        return [
            'schema_version' => 1,
            'id' => 'test-company',
            'company' => [
                'name' => 'Тестовая компания',
                'inn' => '7707083893',
                'website' => 'https://example.org',
                'industry' => 'Финтех',
            ],
            'researched_at' => '2026-10-23T11:00:00Z',
            'summary' => 'Обнаружена публичная закупка',
            'outstaff_demand' => [
                'assessment' => 'confirmed',
                'explanation' => 'Закупка подтверждена источником.',
            ],
            'technologies' => ['PHP', 'Java'],
            'contractors' => [],
            'decision_makers' => [],
            'evidence' => [[
                'title' => 'Публичный источник',
                'url' => 'https://example.org/tender',
                'finding' => 'Описание закупки',
            ]],
            'recommendation' => 'Изучить условия закупки',
            'open_questions' => ['Кто отвечает за подрядчиков?'],
        ];
    }

    private function write(array $data, string $filename = 'test-company.json'): void
    {
        file_put_contents($this->directory.'/'.$filename, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_guest_cannot_view_research_but_authenticated_sales_manager_can(): void
    {
        $this->get('/projects/irlix-sales/research')->assertRedirect('/login');
        $this->actingAs($this->user('user'))
            ->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->has('reports', 0)
            );
    }

    public function test_admin_can_view_published_reports(): void
    {
        $this->write($this->sample());

        $this->actingAs($this->user())
            ->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->has('reports', 1)
                ->where('reports.0.company.name', 'Тестовая компания')
                ->where('reports.0.outstaff_demand.assessment', 'confirmed')
                ->where('reports.0.evidence.0.url', 'https://example.org/tender')
            );
    }

    public function test_invalid_report_is_ignored_instead_of_breaking_page(): void
    {
        $invalid = $this->sample();
        $invalid['id'] = '../bad-file';
        $this->write($invalid);

        $this->actingAs($this->user())
            ->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->has('reports', 0)
            );
    }

    public function test_unsafe_urls_are_removed_and_unproven_confirmed_status_is_downgraded(): void
    {
        $invalid = $this->sample();
        $invalid['company']['website'] = 'javascript:alert(1)';
        $invalid['evidence'][0]['url'] = 'javascript:alert(1)';
        $invalid['decision_makers'] = [[
            'name' => 'Кандидат',
            'role' => 'CIO',
            'public_url' => 'javascript:alert(1)',
        ]];
        $this->write($invalid);

        $reports = (new CompanyResearchRepository($this->directory))->all();
        $this->assertCount(1, $reports);
        $this->assertNull($reports[0]['company']['website']);
        $this->assertCount(0, $reports[0]['decision_makers']);
        $this->assertCount(0, $reports[0]['evidence']);
        $this->assertSame('unclear', $reports[0]['outstaff_demand']['assessment']);
    }

    public function test_extended_public_buyer_report_renders_with_sourced_contacts_and_tenders(): void
    {
        $data = $this->sample();
        $data['company']['ogrn'] = '1141650016540';
        $data['legal_profile'] = [
            'group' => 'Тестовая группа',
            'contracting_entity' => 'ООО «Тест»',
            'description' => 'Проверено по публичному источнику',
            'source_url' => 'https://example.org/legal',
        ];
        $data['financials'] = [[
            'year' => 2025,
            'revenue_rub' => 125000000,
            'profit_rub' => 3500000,
            'source_url' => 'https://example.org/finance',
        ]];
        $data['projects'] = [[
            'name' => 'Личный кабинет', 'details' => 'Публичный проект',
            'source_url' => 'https://example.org/project',
        ]];
        $data['buying_signals'] = [[
            'signal' => 'Тендер', 'evidence_level' => 'A',
            'details' => 'Публичная закупка', 'source_url' => 'https://example.org/tender',
        ]];
        $data['tenders'] = [[
            'title' => 'Веб-разработка', 'platform' => 'Bidzaar', 'number' => '1-2',
            'published_at' => '2026-10-01', 'closed_at' => '2026-10-20',
            'status' => 'Завершён', 'terms' => 'Постоплата 30 дней',
            'roles' => ['Java', 'QA'], 'url' => 'https://example.org/tender',
        ]];
        $data['decision_makers'] = [[
            'name' => 'Проверяемый руководитель',
            'role' => 'CIO', 'company_unit' => 'IT', 'category' => 'it_leadership',
            'priority' => 1, 'role_status' => 'current', 'last_checked_at' => '2026-10-23',
            'responsibility' => 'Руководит IT', 'outreach_angle' => 'Подрядчики',
            'public_url' => 'https://example.org/profile',
            'source_url' => 'https://example.org/role',
            'work_email' => 'it@example.org', 'work_phone' => '+7 495 555-22-33',
        ]];
        $data['business_contacts'] = [[
            'department' => 'Приёмная', 'contact_name' => '',
            'work_email' => 'info@example.org', 'work_phone' => null,
            'purpose' => 'Общий деловой контакт', 'source_url' => 'https://example.org/contacts',
            'last_checked_at' => '2026-10-23',
        ]];
        $data['procurement'] = [
            'platforms' => ['Bidzaar'], 'route' => 'Участие в закупке',
            'requirements' => ['Портфолио'], 'unknowns' => ['Результат'],
        ];
        $data['sales_assessment'] = [
            'grade' => 'B+', 'rationale' => 'Есть подтверждённый IT-запрос',
            'confidence' => 'medium', 'risks' => ['Завершён'],
            'opportunities' => ['Новая закупка'],
        ];
        $data['sales_plan'] = [['step' => 1, 'action' => 'Изучить заказчика', 'target' => 'IT']];
        $data['crm_summary'] = [
            'recommended_status' => 'Изучаем', 'next_action' => 'Проверить ЛПР',
            'focus_roles' => ['Java', 'QA'],
        ];
        $this->write($data);
        $this->actingAs($this->user())
            ->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->where('reports.0.company.ogrn', '1141650016540')
                ->where('reports.0.decision_makers.0.work_email', 'it@example.org')
                ->where('reports.0.business_contacts.0.work_email', 'info@example.org')
                ->where('reports.0.tenders.0.number', '1-2')
                ->where('reports.0.sales_assessment.grade', 'B+')
                ->where('reports.0.financials.0.revenue_rub', 125000000)
            );
    }

    public function test_non_public_or_invalid_business_contact_fields_are_not_rendered(): void
    {
        $data = $this->sample();
        $data['decision_makers'] = [
            [
                'name' => 'CIO', 'role' => 'CIO',
                'source_url' => 'javascript:alert(1)',
                'work_email' => 'test@example.org',
                'work_phone' => '+7 900 000 00 00',
            ],
            [
                'name' => 'Buyer', 'role' => 'Procurement',
                'source_url' => 'https://example.org/role',
                'public_url' => 'javascript:alert(1)',
                'work_email' => 'not-email',
                'work_phone' => 'wrong-number',
                'priority' => 1, 'last_checked_at' => '2026-13-99',
            ],
        ];
        $data['business_contacts'] = [
            ['source_url' => 'javascript:alert(1)', 'work_email' => 'valid@example.org'],
            ['source_url' => 'https://example.org/contacts', 'work_email' => 'bad',
                'work_phone' => 'invalid'],
        ];
        $data['financials'] = [[
            'year' => 2025, 'revenue_rub' => 123,
            'profit_rub' => 12, 'source_url' => 'javascript:alert(1)',
        ]];
        $this->write($data);

        $record = (new CompanyResearchRepository($this->directory))->all()[0];
        $this->assertCount(1, $record['decision_makers']);
        $this->assertNull($record['decision_makers'][0]['public_url']);
        $this->assertNull($record['decision_makers'][0]['work_email']);
        $this->assertNull($record['decision_makers'][0]['work_phone']);
        $this->assertNull($record['decision_makers'][0]['last_checked_at']);
        $this->assertCount(0, $record['business_contacts']);
        $this->assertCount(0, $record['financials']);
    }

    public function test_all_committed_public_reports_are_valid_before_release(): void
    {
        $files = glob(base_path('data/company-reports/*.json')) ?: [];
        $published = (new CompanyResearchRepository())->all();

        $this->assertCount(count($files), $published, 'A committed company report is malformed or its filename/id does not match.');

        foreach ($files as $file) {
            $input = json_decode((string) file_get_contents($file), true);
            $id = pathinfo($file, PATHINFO_FILENAME);
            $record = collect($published)->firstWhere('id', $id);
            $this->assertNotNull($record, 'Missing company report '.$id);
            $this->assertSame(count($input['evidence'] ?? []), count($record['evidence']), 'Invalid evidence URL in '.$id);
            $this->assertSame(count($input['decision_makers'] ?? []), count($record['decision_makers']), 'Non-public or invalid decision maker link in '.$id);
            if (($input['outstaff_demand']['assessment'] ?? '') === 'confirmed') {
                $this->assertSame('confirmed', $record['outstaff_demand']['assessment'], 'Confirmed signals must cite evidence in '.$id);
            }
        }
    }

    public function test_updating_same_file_replaces_old_version_without_duplicate(): void
    {
        $this->write($this->sample());
        $updated = $this->sample();
        $updated['summary'] = 'Уточнённые сведения';
        $updated['researched_at'] = '2026-10-24T10:00:00Z';
        $this->write($updated);

        $reports = (new CompanyResearchRepository($this->directory))->all();
        $this->assertCount(1, $reports);
        $this->assertSame('Уточнённые сведения', $reports[0]['summary']);
    }
}
