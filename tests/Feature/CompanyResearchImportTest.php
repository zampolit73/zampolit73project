<?php

namespace Tests\Feature;

use App\Models\CompanyResearchImport;
use App\Models\CompanyResearchImportRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyResearchImportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'user'): User
    {
        return User::query()->create([
            'username' => 'import-manager-'.$role,
            'role' => $role,
            'password' => Hash::make('sample-password'),
        ]);
    }

    private function payload(string $id = 'inn-7707083893'): array
    {
        return [
            'schema_version' => 1,
            'id' => $id,
            'company' => [
                'name' => 'Тестовая компания',
                'inn' => '7707083893',
                'website' => 'https://example.org',
                'industry' => 'IT',
                'ogrn' => '',
            ],
            'researched_at' => '2026-10-23T12:00:00Z',
            'summary' => 'Тестовый отчёт',
            'outstaff_demand' => [
                'assessment' => 'unclear',
                'explanation' => 'Подтверждений недостаточно.',
            ],
            'evidence' => [[
                'title' => 'Официальная страница',
                'url' => 'https://example.org/source',
                'finding' => 'Есть разработки.',
            ]],
            'decision_makers' => [[
                'name' => 'Директор разработки',
                'role' => 'CTO',
                'source_url' => 'https://example.org/cto',
                'public_url' => 'https://example.org/cto',
                'priority' => 1,
            ]],
            'business_contacts' => [],
            'financials' => [],
            'projects' => [],
            'tenders' => [],
            'buying_signals' => [],
            'technologies' => ['Go'],
            'sales_assessment' => [
                'grade' => 'B',
                'confidence' => 'medium',
                'rationale' => 'Нужна проверка',
            ],
        ];
    }

    private function upload(array $payload, bool $replace = false, bool $reviewed = true): array
    {
        return [
            'report_file' => UploadedFile::fake()->createWithContent(
                'company.json', json_encode($payload, JSON_UNESCAPED_UNICODE)
            ),
            'reviewed' => $reviewed ? '1' : '0',
            'replace_existing' => $replace ? '1' : '0',
        ];
    }

    public function test_guest_cannot_upload(): void
    {
        $this->post('/projects/irlix-sales/research/import', $this->upload($this->payload()))
            ->assertRedirect('/login');
        $this->assertDatabaseCount('company_research_imports', 0);
    }

    public function test_regular_manager_imports_report_into_sqlite_not_github(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post(
            '/projects/irlix-sales/research/import', $this->upload($this->payload())
        )->assertRedirect('/projects/irlix-sales/research');

        $this->assertDatabaseHas('company_research_imports', [
            'identifier' => 'inn-7707083893',
            'company_inn' => '7707083893',
            'revision' => 1,
            'imported_by' => $user->id,
        ]);
        $this->assertDatabaseHas('company_research_import_revisions', [
            'revision' => 1,
            'imported_by' => $user->id,
        ]);
        $this->assertFileDoesNotExist(base_path('data/company-reports/inn-7707083893.json'));

        $this->get('/projects/irlix-sales/research')->assertInertia(fn (Assert $page) => $page
            ->component('IrlixResearch')
            ->where('reports.0.company.name', 'Тестовая компания')
            ->where('reports.0.origin', 'sqlite')
            ->where('reports.0.imported_by_name', $user->username)
            ->where('reports.0.revision', 1)
            ->has('reports.0.decision_makers', 1)
            ->has('reports.0.history', 1)
        );
    }

    public function test_duplicate_requires_explicit_confirmation_and_revisions_are_kept(): void
    {
        $this->actingAs($this->user());
        $payload = $this->payload();
        $this->post('/projects/irlix-sales/research/import', $this->upload($payload))
            ->assertRedirect('/projects/irlix-sales/research');

        $payload['summary'] = 'Новые проверенные сведения';
        $this->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import', $this->upload($payload))
            ->assertSessionHasErrors('replace_existing');
        $this->assertDatabaseHas('company_research_imports', ['revision' => 1]);

        $this->post('/projects/irlix-sales/research/import', $this->upload($payload, true))
            ->assertRedirect('/projects/irlix-sales/research');
        $this->assertDatabaseCount('company_research_imports', 1);
        $this->assertDatabaseCount('company_research_import_revisions', 2);
        $this->assertSame(2, CompanyResearchImport::first()->revision);

        $this->post('/projects/irlix-sales/research/import', $this->upload($payload, true));
        $this->assertDatabaseCount('company_research_import_revisions', 2);
    }

    public function test_public_git_company_is_overridden_privately_without_duplicate(): void
    {
        $this->actingAs($this->user());
        $payload = $this->payload('inn-1650292810');
        $payload['company']['name'] = 'Обновлённый ХАР';
        $payload['company']['inn'] = '1650292810';

        $this->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import', $this->upload($payload))
            ->assertSessionHasErrors('replace_existing');
        $this->post('/projects/irlix-sales/research/import', $this->upload($payload, true))
            ->assertRedirect('/projects/irlix-sales/research');
        $this->get('/projects/irlix-sales/research')->assertInertia(fn (Assert $page) => $page
            ->component('IrlixResearch')
            ->where('reports.0.company.name', 'Обновлённый ХАР')
        );
        $this->assertDatabaseCount('company_research_imports', 1);
    }

    public function test_rejects_dangerous_links_without_persisting_anything(): void
    {
        $this->actingAs($this->user());
        $payload = $this->payload();
        $payload['decision_makers'][0]['source_url'] = 'javascript:alert(1)';
        $this->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import', $this->upload($payload))
            ->assertSessionHasErrors('report_file');
        $this->assertDatabaseCount('company_research_imports', 0);
    }

    public function test_rejects_unverified_confirmed_demand(): void
    {
        $this->actingAs($this->user());
        $payload = $this->payload();
        $payload['outstaff_demand']['assessment'] = 'confirmed';
        $payload['evidence'] = [];
        $this->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import', $this->upload($payload))
            ->assertSessionHasErrors('report_file');
        $this->assertDatabaseCount('company_research_imports', 0);
    }

    public function test_review_confirmation_is_required(): void
    {
        $this->actingAs($this->user())
            ->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import', $this->upload($this->payload(), false, false))
            ->assertSessionHasErrors('reviewed');
        $this->assertDatabaseCount('company_research_imports', 0);
    }

    public function test_untrusted_extra_keys_are_not_persisted(): void
    {
        $this->actingAs($this->user());
        $payload = $this->payload();
        $payload['private_api_key'] = 'should-not-be-saved';
        $this->post('/projects/irlix-sales/research/import', $this->upload($payload))
            ->assertRedirect('/projects/irlix-sales/research');
        $saved = CompanyResearchImport::firstOrFail();
        $this->assertStringNotContainsString('private_api_key', $saved->payload);
        $this->assertStringNotContainsString('should-not-be-saved', $saved->payload);
    }

    private function archive(array $documents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'irlix-zip-');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true);
        foreach ($documents as $name => $document) {
            $zip->addFromString($name, json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $zip->close();

        return new UploadedFile($path, 'research.zip', 'application/zip', null, true);
    }

    public function test_bulk_zip_repairs_aliases_and_imports_all_companies_into_sqlite(): void
    {
        $this->actingAs($this->user());
        $first = $this->payload();
        $first['evidence'][0] = [
            'title' => 'Первичный источник',
            'source_url' => 'https://example.org/source',
            'description' => 'Подтверждённая информация',
        ];
        $first['tenders'] = [[
            'title' => 'ИТ-тендер',
            'source_url' => 'https://example.org/tender',
            'description' => 'Проверенные условия',
            'customer' => 'Тестовая компания',
        ]];
        $first['financials'] = [[
            'year' => 2025,
            'revenue_rub' => 1000000,
            'net_profit_rub' => 120000,
            'source_url' => 'https://example.org/finance',
        ]];
        $second = $this->payload('inn-7708730092');
        $second['company']['inn'] = '7708730092';
        $second['company']['name'] = 'Логистика тест';

        $response = $this->post('/projects/irlix-sales/research/import-archive', [
            'archive' => $this->archive([
                'inn-7707083893.json' => $first,
                'inn-7708730092.json' => $second,
            ]),
            'reviewed' => '1',
        ])->assertRedirect('/projects/irlix-sales/research');

        $response->assertSessionHas('research_batch_import_result.total', 2);
        $this->assertDatabaseCount('company_research_imports', 2);
        $this->assertDatabaseCount('company_research_import_revisions', 2);
        $imported = CompanyResearchImport::query()->where('company_inn', '7707083893')->firstOrFail();
        $data = json_decode($imported->payload, true);
        $this->assertSame(120000, $data['financials'][0]['profit_rub']);
        $this->assertSame('https://example.org/tender', $data['tenders'][0]['url']);
        $this->assertStringContainsString('Заказчик: Тестовая компания', $data['tenders'][0]['terms']);
        $this->assertSame('Подтверждённая информация', $data['evidence'][0]['finding']);
    }

    public function test_bulk_zip_invalid_second_report_rolls_back_entire_batch(): void
    {
        $this->actingAs($this->user());
        $first = $this->payload();
        $second = $this->payload('inn-7708730092');
        $second['company']['inn'] = '7708730092';
        $second['evidence'][0]['url'] = 'javascript:alert(1)';

        $this->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import-archive', [
                'archive' => $this->archive(['first.json' => $first, 'second.json' => $second]),
                'reviewed' => '1',
            ])
            ->assertSessionHasErrors('archive');

        $this->assertDatabaseCount('company_research_imports', 0);
        $this->assertDatabaseCount('company_research_import_revisions', 0);
    }

    public function test_bulk_zip_does_not_replace_existing_company_without_consent(): void
    {
        $this->actingAs($this->user());
        $first = $this->payload();
        $this->post('/projects/irlix-sales/research/import', $this->upload($first))
            ->assertRedirect('/projects/irlix-sales/research');

        $first['summary'] = 'Исправленное исследование';
        $this->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import-archive', [
                'archive' => $this->archive(['first.json' => $first]),
                'reviewed' => '1',
            ])
            ->assertSessionHasErrors('replace_existing');

        $this->assertSame(1, CompanyResearchImport::firstOrFail()->revision);
        $this->post('/projects/irlix-sales/research/import-archive', [
            'archive' => $this->archive(['first.json' => $first]),
            'reviewed' => '1',
            'replace_existing' => '1',
        ])->assertRedirect('/projects/irlix-sales/research');
        $this->assertSame(2, CompanyResearchImport::firstOrFail()->revision);
    }

    public function test_bulk_zip_requires_authenticated_review(): void
    {
        $this->post('/projects/irlix-sales/research/import-archive', [
            'archive' => $this->archive(['first.json' => $this->payload()]),
            'reviewed' => '1',
        ])->assertRedirect('/login');

        $this->actingAs($this->user())
            ->from('/projects/irlix-sales/research')
            ->post('/projects/irlix-sales/research/import-archive', [
                'archive' => $this->archive(['first.json' => $this->payload()]),
                'reviewed' => '0',
            ])->assertSessionHasErrors('reviewed');

        $this->assertDatabaseCount('company_research_imports', 0);
    }

}
