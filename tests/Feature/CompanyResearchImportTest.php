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
}
