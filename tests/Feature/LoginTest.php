<?php

namespace Tests\Feature;

use Tests\TestCase;

class LoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['auth.demo_user' => [
            'name' => 'Demo Parent',
            'email' => 'parent@example.com',
            'password' => 'password',
        ]]);
    }

    public function test_demo_credentials_log_in_and_return_the_user(): void
    {
        $this->postJson('/api/login', ['email' => 'parent@example.com', 'password' => 'password'])
            ->assertOk()
            ->assertExactJson(['user' => ['name' => 'Demo Parent', 'email' => 'parent@example.com']]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->postJson('/api/login', ['email' => 'parent@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_missing_fields_are_rejected(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_page_renders_react_mount_point(): void
    {
        $this->withoutVite()
            ->get('/login')
            ->assertOk()
            ->assertSee('id="app"', false);
    }
}
