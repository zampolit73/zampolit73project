<?php

namespace Tests\Feature;

use App\Models\CompanyResearchImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CompanyResearchJsonRepairTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::query()->create([
            'username' => 'research-repair-manager',
            'role' => 'user',
            'password' => Hash::make('sample-password'),
        ]);
    }

    private function document(): array
    {
        return [
            'schema_version' => 1,
            'id' => 'inn-7715641372',
            'company' => ['name' => 'Авалон Лоджистикс', 'inn' => '7715641372'],
            'researched_at' => '2026-10-28T10:18:43Z',
            'outstaff_demand' => ['assessment' => 'unclear', 'explanation' => 'Нет доказательства внешней закупки'],
            'evidence' => [['title' => 'Официальная страница', 'finding' => 'Есть WMS', 'url' => 'https://example.org/wms']],
            'projects' => [[
                'name' => 'WMS AWACS',
                'description' => 'Внутренняя система управления складом',
                'url' => 'https://example.org/wms',
                'status' => 'existing',
                'contractor' => 'Внешний подрядчик не установлен',
            ]],
            'buying_signals' => [[
                'title' => 'Штатный поиск интегратора',
                'finding' => 'Не доказывает покупку аутстаффинга',
                'url' => 'https://example.org/jobs',
                'category' => 'hiring',
                'published_at' => '2026-10-23',
            ]],
            'financials' => [[
                'year' => 2025,
                'revenue' => 2963787000,
                'net_profit' => 82169000,
                'currency' => 'RUB',
                'source_url' => 'https://example.org/finances',
                'note' => 'Историческая отчётность',
            ]],
        ];
    }

    private function upload(array $payload, string $name = 'company.json'): array
    {
        return ['report_file' => UploadedFile::fake()->createWithContent(
            $name, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )];
    }

    public function test_only_authenticated_users_can_repair(): void
    {
        $this->post('/projects/irlix-sales/research/repair', $this->upload($this->document()))
            ->assertRedirect('/login');
        $this->assertDatabaseCount('company_research_imports', 0);
    }

    public function test_repairs_project_signals_and_financial_aliases_without_persistence(): void
    {
        $this->actingAs($this->user());
        $response = $this->withHeaders(['Accept' => 'application/json'])->post('/projects/irlix-sales/research/repair', $this->upload($this->document()))
            ->assertOk()
            ->assertJsonPath('report.schema_version', 1)
            ->assertJsonPath('report.company.inn', '7715641372')
            ->assertJsonPath('report.projects.0.details', 'Внутренняя система управления складом; Подрядчик: Внешний подрядчик не установлен; Статус: existing')
            ->assertJsonPath('report.projects.0.source_url', 'https://example.org/wms')
            ->assertJsonPath('report.buying_signals.0.evidence_level', 'C')
            ->assertJsonPath('report.buying_signals.0.source_url', 'https://example.org/jobs')
            ->assertJsonPath('report.financials.0.revenue_rub', 2963787000)
            ->assertJsonPath('report.financials.0.profit_rub', 82169000);

        $this->assertNotEmpty($response->json('changes'));
        $this->assertStringContainsString('financials[0].note', implode('; ', $response->json('warnings')));
        $this->assertDatabaseCount('company_research_imports', 0);
        $this->assertDatabaseCount('company_research_import_revisions', 0);

        // The returned payload can be saved via the normal strict uploader.
        $this->post('/projects/irlix-sales/research/import', $this->upload($response->json('report')) + [
            'reviewed' => '1', 'replace_existing' => '0',
        ])->assertRedirect('/projects/irlix-sales/research');
        $saved = CompanyResearchImport::firstOrFail();
        $this->assertSame(1, $saved->revision);
        $this->assertSame(1, count(json_decode($saved->payload, true)['projects']));
    }

    public function test_repairs_external_development_contractors_and_sales_plan(): void
    {
        $payload = $this->document();
        $payload['id'] = 'inn-9727130961';
        $payload['company']['inn'] = '9727130961';
        $payload['contractors'] = [[
            'name' => 'СимбирСофт',
            'relationship' => 'confirmed_external_development',
            'service' => 'Доработки 1С:ERP',
            'source_url' => 'https://example.org/case',
            'notes' => 'Договорная модель не раскрыта',
        ]];
        $payload['sales_plan'] = [[
            'step' => 1,
            'action' => 'Уточнить потребность',
            'audience' => 'IT-директор',
            'goal' => 'Выяснить модель закупки',
        ]];

        $this->actingAs($this->user())
            ->withHeaders(['Accept' => 'application/json'])->post('/projects/irlix-sales/research/repair', $this->upload($payload))
            ->assertOk()
            ->assertJsonPath('report.contractors.0.relationship', 'confirmed')
            ->assertJsonPath('report.contractors.0.note',
                'Договорная модель не раскрыта; Услуга: Доработки 1С:ERP; Источник: https://example.org/case')
            ->assertJsonPath('report.sales_plan.0.action', 'Уточнить потребность; Цель: Выяснить модель закупки')
            ->assertJsonPath('report.sales_plan.0.target', 'IT-директор');
        $this->assertDatabaseCount('company_research_imports', 0);
    }

    public function test_rejects_unsafe_urls_instead_of_discarding_evidence(): void
    {
        $payload = $this->document();
        $payload['projects'][0]['url'] = 'javascript:alert(1)';
        $this->actingAs($this->user())
            ->withHeaders(['Accept' => 'application/json'])->post('/projects/irlix-sales/research/repair', $this->upload($payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('report_file');
        $this->assertDatabaseCount('company_research_imports', 0);
    }

    public function test_rejects_conflicting_alias_values(): void
    {
        $payload = $this->document();
        $payload['projects'][0]['source_url'] = 'https://example.org/another';
        $this->actingAs($this->user())
            ->withHeaders(['Accept' => 'application/json'])->post('/projects/irlix-sales/research/repair', $this->upload($payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('report_file');
    }

    public function test_rejects_non_rub_financial_data(): void
    {
        $payload = $this->document();
        $payload['financials'][0]['currency'] = 'USD';
        $this->actingAs($this->user())
            ->withHeaders(['Accept' => 'application/json'])->post('/projects/irlix-sales/research/repair', $this->upload($payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('report_file');
    }

    public function test_rejects_unsafe_or_missing_identity(): void
    {
        $payload = $this->document();
        $payload['company']['inn'] = '0000000000';
        $this->actingAs($this->user())
            ->withHeaders(['Accept' => 'application/json'])->post('/projects/irlix-sales/research/repair', $this->upload($payload))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('report_file');
    }

    public function test_valid_canonical_report_is_returned_without_changes(): void
    {
        $payload = $this->document();
        $payload['projects'] = [];
        $payload['buying_signals'] = [];
        $payload['financials'] = [];
        $this->actingAs($this->user())
            ->withHeaders(['Accept' => 'application/json'])->post('/projects/irlix-sales/research/repair', $this->upload($payload))
            ->assertOk()
            ->assertJsonCount(0, 'changes')
            ->assertJsonCount(0, 'warnings');
        $this->assertDatabaseCount('company_research_imports', 0);
    }
}
