<?php

namespace Tests\Feature;

use App\Models\KommersantActivity;
use App\Models\KommersantCandidate;
use App\Models\KommersantCategory;
use App\Models\KommersantManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KommersantRankingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username = 'ranking-user'): User
    {
        return User::query()->create([
            'username' => $username,
            'password' => Hash::make('password'),
            'role' => 'user',
        ]);
    }

    public function test_initial_2026_dataset_is_imported_once_with_expected_shape(): void
    {
        $this->assertDatabaseCount('kommersant_categories', 19);
        $this->assertDatabaseCount('kommersant_managers', 1120);
        $this->assertDatabaseCount('kommersant_candidates', 55);

        $this->assertSame(
            338,
            KommersantManager::query()->whereNotNull('linkedin_url')->count(),
        );

        $commercial = KommersantCategory::query()->where('slug', 'commercial')->firstOrFail();

        $this->assertSame(100, $commercial->managers()->count());
        $this->assertDatabaseHas('kommersant_managers', [
            'category_id' => $commercial->id,
            'row_number' => 1,
            'full_name' => 'Головин Дмитрий Сергеевич',
            'job_title' => 'Коммерческий директор',
            'company' => '«МТС Линк»',
            'pdf_page' => 3,
        ]);
    }

    public function test_guest_is_redirected_and_regular_user_can_open_project(): void
    {
        $this->get('/projects/kommersant-ranking')->assertRedirect('/login');

        $this->actingAs($this->user())
            ->get('/projects/kommersant-ranking?tab=commercial')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('KommersantRanking')
                ->where('tab', 'commercial')
                ->where('stats.total', 1120)
                ->where('stats.candidates', 55)
                ->has('managers.data', 50)
            );
    }

    public function test_user_can_edit_and_clear_linkedin_but_only_with_linkedin_host(): void
    {
        $user = $this->user();
        $manager = KommersantManager::query()
            ->where('full_name', 'Головин Дмитрий Сергеевич')
            ->firstOrFail();

        $this->actingAs($user)
            ->patch('/projects/kommersant-ranking/managers/'.$manager->id, [
                'linkedin_url' => 'https://www.linkedin.com/in/updated-golovin',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('kommersant_managers', [
            'id' => $manager->id,
            'linkedin_url' => 'https://www.linkedin.com/in/updated-golovin',
        ]);

        $this->assertDatabaseHas('kommersant_activities', [
            'user_id' => $user->id,
            'subject_type' => 'manager',
            'subject_id' => $manager->id,
            'action' => 'linkedin_updated',
        ]);

        $this->actingAs($user)
            ->from('/projects/kommersant-ranking?tab=commercial')
            ->patch('/projects/kommersant-ranking/managers/'.$manager->id, [
                'linkedin_url' => 'https://example.com/not-linkedin',
            ])
            ->assertRedirect('/projects/kommersant-ranking?tab=commercial')
            ->assertSessionHasErrors('linkedin_url');

        $this->actingAs($user)
            ->patch('/projects/kommersant-ranking/managers/'.$manager->id, [
                'linkedin_url' => null,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('kommersant_managers', [
            'id' => $manager->id,
            'linkedin_url' => null,
        ]);
    }

    public function test_manager_assignment_is_atomic_and_owner_only_release(): void
    {
        $owner = $this->user('ranking-owner');
        $other = $this->user('ranking-other');
        $manager = KommersantManager::query()->firstOrFail();

        $this->actingAs($owner)
            ->patch('/projects/kommersant-ranking/managers/'.$manager->id, [
                'assignment_action' => 'take',
            ])
            ->assertRedirect();

        $manager->refresh();
        $this->assertSame($owner->id, $manager->assigned_to_user_id);
        $this->assertNotNull($manager->assigned_at);

        $this->actingAs($other)
            ->from('/projects/kommersant-ranking?tab=executives')
            ->patch('/projects/kommersant-ranking/managers/'.$manager->id, [
                'assignment_action' => 'take',
            ])
            ->assertRedirect('/projects/kommersant-ranking?tab=executives')
            ->assertSessionHasErrors('assignment');

        $this->actingAs($other)
            ->from('/projects/kommersant-ranking?tab=executives')
            ->patch('/projects/kommersant-ranking/managers/'.$manager->id, [
                'assignment_action' => 'release',
            ])
            ->assertRedirect('/projects/kommersant-ranking?tab=executives')
            ->assertSessionHasErrors('assignment');

        $this->actingAs($owner)
            ->patch('/projects/kommersant-ranking/managers/'.$manager->id, [
                'assignment_action' => 'release',
            ])
            ->assertRedirect();

        $manager->refresh();
        $this->assertNull($manager->assigned_to_user_id);
        $this->assertNull($manager->assigned_at);
        $this->assertSame(2, KommersantActivity::query()->where('subject_type', 'manager')->where('subject_id', $manager->id)->count());
    }

    public function test_candidate_supports_assignment_and_linkedin_editing(): void
    {
        $user = $this->user();
        $candidate = KommersantCandidate::query()->firstOrFail();

        $this->actingAs($user)
            ->patch('/projects/kommersant-ranking/candidates/'.$candidate->id, [
                'assignment_action' => 'take',
                'linkedin_url' => 'https://ru.linkedin.com/in/manual-check',
            ])
            ->assertRedirect();

        $candidate->refresh();

        $this->assertSame($user->id, $candidate->assigned_to_user_id);
        $this->assertSame('https://ru.linkedin.com/in/manual-check', $candidate->linkedin_url);
    }

    public function test_assignee_filter_can_show_only_my_rows(): void
    {
        $me = $this->user('ranking-me');
        $manager = KommersantManager::query()
            ->whereHas('category', fn ($query) => $query->where('slug', 'commercial'))
            ->firstOrFail();

        $manager->update([
            'assigned_to_user_id' => $me->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($me)
            ->get('/projects/kommersant-ranking?tab=commercial&assignee=mine')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.assignee', 'mine')
                ->has('managers.data', 1)
                ->where('managers.data.0.id', $manager->id)
            );
    }
}
