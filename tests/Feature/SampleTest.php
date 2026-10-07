<?php

namespace Tests\Feature;

use Tests\TestCase;

class SampleTest extends TestCase
{
    public function test_ping_api_returns_ok_with_database_name(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'database' => 'ottodot_test']);
    }

    public function test_sample_page_renders(): void
    {
        $this->get('/sample')
            ->assertOk()
            ->assertSee('Ottodot Trial Booking')
            ->assertSee('/api/ping');
    }
}
