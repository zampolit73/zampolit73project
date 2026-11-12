<?php

namespace Tests\Feature;

use App\Models\CompanyResearchImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyResearchDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $role = 'user'): User
    {
        return User::query()->create([
            'username' => $name,
            'role' => $role,
            'password' => Hash::make('test-password'),
        ]);
    }

    private function payload(string $id = 'inn-7707083893', string $inn = '7707083893'): array
    {
        return [
            'schema_version' => 1,
            'id' => $id,
            'company' => [
                'name' => 'Тестовая компания',
                'inn' => $inn,
                'website' => 'https://example.org',
                'industry' => 'IT',
            ],
            'researched_at' => '2026-11-12T10:00:00Z',
            'summary' => 'Исследование',
            'outstaff_demand' => [
                'assessment' => 'unclear',
                'explanation' => 'Прямая внешняя закупка не подтверждена',
            ],
            'evidence' => [['title' => 'Источник', 'url' => 'https://example.org/evidence', 'finding' => 'Факт']],
            'decision_makers' => [],
            'business_contacts' => [],
            'financials' => [],
            'projects' => [],
            'tenders' => [],
            'buying_signals' => [],
            'technologies' => ['Java'],
            'sales_assessment' => ['grade' => 'B', 'confidence' => 'medium', 'rationale' => 'Проверить'],
        ];
    }

    private function import(User $user, array $payload, bool $replace = false): CompanyResearchImport
    {
        $this->actingAs($user)->post('/projects/irlix-sales/research/import', [
            'report_file' => UploadedFile::fake()->createWithContent(
                $payload['id'].'.json', json_encode($payload, JSON_UNESCAPED_UNICODE)
            ),
            'reviewed' => '1',
            'replace_existing' => $replace ? '1' : '0',
        ])->assertRedirect('/projects/irlix-sales/research');

        return CompanyResearchImport::query()->where('identifier', $payload['id'])->firstOrFail();
    }

    private function deleteEndpoint(CompanyResearchImport $record): string
    {
        return '/projects/irlix-sales/research/imports/'.$record->id;
    }

    public function test_guest_cannot_delete_research(): void
    {
        $owner = $this->user('owner');
        $record = $this->import($owner, $this->payload());

        auth()->logout();

        $this->delete($this->deleteEndpoint($record), [
            'confirmation_name' => $record->company_name,
        ])->assertRedirect('/login');

        $this->assertModelExists($record);
    }

    public function test_original_uploader_can_delete_own_report_and_all_versions_but_not_other_companies(): void
    {
        $owner = $this->user('original-owner');
        $record = $this->import($owner, $this->payload());
        $second = $this->payload('inn-7708730092', '7708730092');
        $otherRecord = $this->import($owner, $second);

        $updated = $this->payload();
        $updated['summary'] = 'Проверенные новые факты';
        $this->import($owner, $updated, true);

        $this->assertDatabaseHas('company_research_imports', [
            'id' => $record->id, 'revision' => 2,
        ]);
        $this->assertDatabaseCount('company_research_import_revisions', 3);

        $this->actingAs($owner)->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->where('reports.0.import_record_id', $record->id)
                ->where('reports.0.can_delete', true));

        $this->actingAs($owner)->delete($this->deleteEndpoint($record), [
            'confirmation_name' => $record->company_name,
        ])->assertRedirect('/projects/irlix-sales/research')
            ->assertSessionHas('research_deleted_result.company', 'Тестовая компания');

        $this->assertModelMissing($record);
        $this->assertModelExists($otherRecord);
        $this->assertDatabaseCount('company_research_imports', 1);
        $this->assertDatabaseCount('company_research_import_revisions', 1);
    }

    public function test_other_employee_cannot_delete_even_after_uploading_new_revision(): void
    {
        $original = $this->user('original-sales');
        $collaborator = $this->user('collaborator-sales');
        $record = $this->import($original, $this->payload());

        $updated = $this->payload();
        $updated['summary'] = 'Обновил другой менеджер';
        $this->import($collaborator, $updated, true);

        $this->actingAs($collaborator)->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->where('reports.0.import_record_id', $record->id)
                ->where('reports.0.can_delete', false)
                ->where('reports.0.imported_by_name', 'collaborator-sales'));

        $this->actingAs($collaborator)->delete($this->deleteEndpoint($record), [
            'confirmation_name' => $record->company_name,
        ])->assertForbidden();

        $this->actingAs($original)->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->where('reports.0.can_delete', true));

        $this->assertModelExists($record);
        $this->assertDatabaseCount('company_research_import_revisions', 2);
    }

    public function test_incorrect_or_missing_confirmation_prevents_delete(): void
    {
        $owner = $this->user('confirm-owner');
        $record = $this->import($owner, $this->payload());

        $this->actingAs($owner)->from('/projects/irlix-sales/research')
            ->delete($this->deleteEndpoint($record), [
                'confirmation_name' => 'Не то название',
            ])->assertSessionHasErrors('confirmation_name');

        $this->actingAs($owner)->from('/projects/irlix-sales/research')
            ->delete($this->deleteEndpoint($record), [])
            ->assertSessionHasErrors('confirmation_name');

        $this->assertModelExists($record);
        $this->assertDatabaseCount('company_research_import_revisions', 1);
    }

    public function test_admin_can_delete_any_import_including_orphaned_owner(): void
    {
        $owner = $this->user('removed-owner');
        $admin = $this->user('site-admin', 'admin');
        $record = $this->import($owner, $this->payload());

        $owner->delete();
        $this->assertDatabaseHas('company_research_import_revisions', [
            'research_import_id' => $record->id, 'imported_by' => null,
        ]);

        $this->actingAs($admin)->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->where('reports.0.can_delete', true));

        $this->actingAs($admin)->delete($this->deleteEndpoint($record), [
            'confirmation_name' => $record->company_name,
        ])->assertRedirect('/projects/irlix-sales/research');

        $this->assertModelMissing($record);
    }

    public function test_public_github_research_cannot_be_deleted_through_private_import_route(): void
    {
        $admin = $this->user('site-admin', 'admin');

        $this->actingAs($admin)->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->where('reports.0.origin', 'github')
                ->where('reports.0.can_delete', false));

        $this->actingAs($admin)->delete('/projects/irlix-sales/research/imports/999999', [
            'confirmation_name' => 'Любая компания',
        ])->assertNotFound();
    }

    public function test_deleting_private_override_reveals_public_github_version(): void
    {
        $owner = $this->user('overriding-owner');
        $payload = $this->payload('inn-1650292810', '1650292810');
        $payload['company']['name'] = 'Частная версия ХАР';
        $record = $this->import($owner, $payload, true);

        $this->actingAs($owner)->delete($this->deleteEndpoint($record), [
            'confirmation_name' => $record->company_name,
        ])->assertRedirect('/projects/irlix-sales/research');

        $this->actingAs($owner)->get('/projects/irlix-sales/research')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixResearch')
                ->where('reports.0.origin', 'github')
                ->where('reports.0.can_delete', false));

        $this->assertModelMissing($record);
    }
}
