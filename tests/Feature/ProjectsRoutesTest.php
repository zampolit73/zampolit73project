<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProjectsRoutesTest extends TestCase
{
    public function test_projects_catalog_is_public(): void
    {
        $this->get('/projects')->assertOk();
    }

    public function test_bmp_to_mip_converter_is_public(): void
    {
        $this->get('/projects/bmp-to-mip')->assertOk();
    }

    public function test_pushkin_fairytales_book_is_public(): void
    {
        $this->get('/projects/pushkin-fairytales')->assertOk();
    }

    public function test_dog_training_ground_is_public(): void
    {
        $this->get('/projects/dog-training-ground')->assertOk();
    }

    public function test_dog_training_ground_forces_full_reload_for_inertia_visits(): void
    {
        $this->get('/projects/dog-training-ground', [
            'X-Inertia' => 'true',
        ])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', '/projects/dog-training-ground');
    }
}
