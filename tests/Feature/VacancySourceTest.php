<?php

namespace Tests\Feature;

use App\Jobs\RunVacancyInvestigation;
use App\Models\User;
use App\Models\VacancyInvestigation;
use App\Services\TelegramBotClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_demo_job_runs_pipeline_to_completion(): void
    {
        config()->set('vacancy_source.demo_stage_delay_ms', 0);

        $user = $this->user();

        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $user->id,
            'input_source' => 'web',
            'input_text' => 'Java Kafka Camunda vacancy used to exercise the async skeleton.',
            'status' => 'queued',
            'progress_stage' => 'queued',
            'queued_at' => now(),
        ]);

        (new RunVacancyInvestigation($investigation->id))->handle(app(TelegramBotClient::class));

        $investigation->refresh();

        $this->assertSame('completed', $investigation->status);
        $this->assertSame('completed', $investigation->progress_stage);
        $this->assertSame('Готово', $investigation->progress_text);
        $this->assertNotNull($investigation->started_at);
        $this->assertNotNull($investigation->finished_at);
        $this->assertStringContainsString('Технический каркас', $investigation->result_summary);
    }
}
