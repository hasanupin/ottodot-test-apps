<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagementPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/trial-classes', '/teachers', '/parents', '/students', '/bookings', '/payments', '/roster'];

    public function test_guests_are_sent_to_login(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertRedirect('/login');
        }
    }

    public function test_logged_in_users_get_the_app_shell(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (self::PAGES as $page) {
            $this->withoutVite()->get($page)->assertOk()->assertSee('id="app"', false);
        }
    }
}
