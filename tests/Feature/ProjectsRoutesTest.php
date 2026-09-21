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
}
