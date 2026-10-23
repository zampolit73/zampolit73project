<?php

namespace Tests\Feature;

use App\Models\InvestigationCandidate;
use App\Models\SalesProspect;
use App\Models\User;
use App\Models\VacancyInvestigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesProspectTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username = 'sales', string $role = 'user'): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => Hash::make('password'),
            'role' => $role,
        ]);
    }

    private function payload(string $name = 'Тестовый заказчик'): array
    {
        return [
            'name' => $name,
            'inn' => '7707083893',
            'website' => 'https://example.org',
            'status' => 'new',
            'priority' => 'medium',
            'owner_id' => null,
            'contact_name' => null,
            'contact_role' => null,
            'contact_url' => null,
            'evidence_url' => null,
            'notes' => 'Нужно проверить закупки',
            'next_step' => null,
        ];
    }

    public function test_guest_cannot_access_board(): void
    {
        $this->get('/projects/irlix-sales')->assertRedirect('/login');
        $this->post('/projects/irlix-sales/prospects', $this->payload())->assertRedirect('/login');
    }

    public function test_authenticated_user_can_add_find_and_update_company(): void
    {
        $user = $this->user();
        $this->actingAs($user)
            ->post('/projects/irlix-sales/prospects', $this->payload())
            ->assertRedirect();

        $prospect = SalesProspect::query()->firstOrFail();
        $this->assertSame('тестовый заказчик', $prospect->normalized_name);

        $this->actingAs($user)
            ->get('/projects/irlix-sales?search=Тестовый&status=new')
            ->assertInertia(fn (Assert $page) => $page
                ->component('IrlixSales')
                ->where('stats.total', 1)
                ->has('prospects.data', 1)
            );

        $updated = $this->payload();
        $updated['status'] = 'contacted';
        $updated['owner_id'] = $user->id;
        $this->actingAs($user)
            ->patch('/projects/irlix-sales/prospects/'.$prospect->id, $updated)
            ->assertRedirect();

        $this->assertDatabaseHas('sales_prospects', [
            'id' => $prospect->id, 'status' => 'contacted', 'owner_id' => $user->id,
        ]);
    }

    public function test_duplicate_name_or_inn_is_rejected(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post('/projects/irlix-sales/prospects', $this->payload());

        $sameName = $this->payload(' ТЕСТОВЫЙ    ЗАКАЗЧИК ');
        $sameName['inn'] = null;
        $this->actingAs($user)
            ->post('/projects/irlix-sales/prospects', $sameName)
            ->assertSessionHasErrors('name');

        $this->actingAs($user)
            ->post('/projects/irlix-sales/prospects', $this->payload('Другой заказчик'))
            ->assertSessionHasErrors('inn');

        $this->assertDatabaseCount('sales_prospects', 1);
    }

    public function test_untrusted_url_and_unknown_owner_are_rejected(): void
    {
        $user = $this->user();
        $input = $this->payload();
        $input['contact_url'] = 'not-a-valid-web-url';
        $input['owner_id'] = 999999;

        $this->actingAs($user)->post('/projects/irlix-sales/prospects', $input)
            ->assertSessionHasErrors(['contact_url', 'owner_id']);
    }

    public function test_import_candidate_is_manual_idempotent_and_private(): void
    {
        $owner = $this->user('vacancy-owner');
        $another = $this->user('another');
        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $owner->id,
            'input_source' => 'web',
            'input_text' => str_repeat('Java ', 10),
            'status' => 'completed',
        ]);
        $candidate = InvestigationCandidate::query()->create([
            'investigation_id' => $investigation->id,
            'company_name' => 'Предполагаемый клиент',
            'candidate_type' => 'indirect',
            'confidence' => 61,
            'is_end_client' => true,
            'rank' => 1,
        ]);

        $this->actingAs($another)->post('/projects/irlix-sales/import-candidate/'.$candidate->id)
            ->assertNotFound();
        $this->actingAs($owner)->post('/projects/irlix-sales/import-candidate/'.$candidate->id)
            ->assertRedirect();
        $this->actingAs($owner)->post('/projects/irlix-sales/import-candidate/'.$candidate->id)
            ->assertRedirect();

        $this->assertDatabaseCount('sales_prospects', 1);
        $this->assertSame('new', SalesProspect::query()->firstOrFail()->status);
    }
}
