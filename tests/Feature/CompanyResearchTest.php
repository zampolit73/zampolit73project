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

    public function test_guest_and_regular_user_cannot_view_research(): void
    {
        $this->get('/projects/irlix-sales/research')->assertRedirect('/login');
        $this->actingAs($this->user('user'))
            ->get('/projects/irlix-sales/research')->assertForbidden();
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
