<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StasPageTest extends TestCase
{
    public function test_stas_page_is_publicly_available(): void
    {
        $this->get('/stas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Stas'));
    }
}
