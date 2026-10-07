<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_logs_in_and_gets_the_standard_success_response(): void
    {
        $user = User::factory()->parent()->create(['name' => 'Alex Parent', 'email' => 'alex@example.com']);

        $this->postJson('/api/login', ['email' => 'alex@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Logged in.',
                'data' => ['user' => ['id' => $user->id, 'name' => 'Alex Parent', 'email' => 'alex@example.com', 'role' => 'parent']],
            ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_teacher_and_admin_log_in_with_their_role(): void
    {
        foreach (['teacher', 'admin'] as $role) {
            $user = User::factory()->{$role}()->create();

            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
                ->assertOk()
                ->assertJsonPath('data.user.role', $role);
        }
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->parent()->create();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('auth.failed'))
            ->assertJsonPath('errors.email.0', __('auth.failed'));

        $this->assertGuest();
    }

    public function test_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    public function test_missing_fields_are_rejected_by_the_form_request(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_invalid_email_format_is_rejected(): void
    {
        $this->postJson('/api/login', ['email' => 'not-an-email', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'x'])
            ->assertTooManyRequests()
            ->assertJsonPath('success', false);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
    }

    public function test_me_returns_the_logged_in_user(): void
    {
        $user = User::factory()->teacher()->create();

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role', 'teacher');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs(User::factory()->parent()->create())
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Logged out.']);

        $this->assertGuest('web');
    }

    public function test_guest_is_redirected_from_the_app_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_logged_in_user_gets_the_app_shell(): void
    {
        $this->withoutVite()->actingAs(User::factory()->admin()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('id="app"', false);
    }

    public function test_login_page_is_for_guests_only(): void
    {
        $this->withoutVite()->get('/login')->assertOk()->assertSee('id="app"', false);

        $this->actingAs(User::factory()->admin()->create())->get('/login')->assertRedirect('/');
    }

    public function test_role_middleware_blocks_other_roles(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'role:admin'])->get('/api/test-admin-only', fn () => 'ok');

        $this->actingAs(User::factory()->parent()->create())
            ->getJson('/api/test-admin-only')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/test-admin-only')
            ->assertOk();
    }
}
