<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\TestCase;

/** Teachers and parents: each is a role row plus its users row (name, email, password). */
class AccountManagementTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    public function test_admin_creates_a_teacher_who_can_log_in(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/teachers', ['name' => 'Ms. Rivera', 'email' => 'rivera@example.com', 'password' => 'secret-pass'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ms. Rivera')
            ->assertJsonPath('data.email', 'rivera@example.com')
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'rivera@example.com')->firstOrFail();
        $this->assertSame('teacher', $user->role->value);
        $this->assertNotNull(Teacher::find($user->teacher_id));

        $this->postJson('/api/login', ['email' => 'rivera@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'teacher');
    }

    public function test_admin_creates_a_parent_who_can_log_in(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/parents', ['name' => 'Maya Tan', 'email' => 'maya@example.com', 'password' => 'secret-pass'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Maya Tan');

        $user = User::where('email', 'maya@example.com')->firstOrFail();
        $this->assertSame('parent', $user->role->value);
        $this->assertNotNull(Guardian::find($user->parent_id));

        $this->postJson('/api/login', ['email' => 'maya@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'parent');
    }

    public function test_create_validates_name_email_and_password(): void
    {
        $admin = $this->admin();
        $taken = $this->teacherUser();

        foreach (['/api/teachers', '/api/parents'] as $url) {
            $this->actingAs($admin)->postJson($url, [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'email', 'password']);

            $this->actingAs($admin)->postJson($url, ['name' => 'X', 'email' => 'not-an-email', 'password' => 'short'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email', 'password']);

            $this->actingAs($admin)->postJson($url, ['name' => 'X', 'email' => $taken->email, 'password' => 'secret-pass'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        }

        $this->assertSame(2, User::count());
    }

    public function test_update_without_password_keeps_the_old_one(): void
    {
        $teacher = $this->teacherUser();

        $this->actingAs($this->admin())
            ->putJson("/api/teachers/{$teacher->teacher_id}", ['name' => 'Renamed', 'email' => $teacher->email])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $fresh = $teacher->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertTrue(Hash::check('password', $fresh->password));
    }

    public function test_update_can_change_the_password(): void
    {
        $parent = $this->parentUser();

        $this->actingAs($this->admin())
            ->putJson("/api/parents/{$parent->parent_id}", ['name' => $parent->name, 'email' => $parent->email, 'password' => 'new-secret'])
            ->assertOk();

        $this->assertTrue(Hash::check('new-secret', $parent->fresh()->password));
    }

    public function test_update_rejects_another_accounts_email(): void
    {
        $parent = $this->parentUser();
        $other = $this->parentUser();

        $this->actingAs($this->admin())
            ->putJson("/api/parents/{$parent->parent_id}", ['name' => 'X', 'email' => $other->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_teacher_with_classes_cannot_be_deleted(): void
    {
        $teacher = $this->teacherUser();
        $this->trialClass($teacher);

        $this->actingAs($this->admin())->deleteJson("/api/teachers/{$teacher->teacher_id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertModelExists($teacher);
    }

    public function test_admin_deletes_a_teacher_and_their_login(): void
    {
        $teacher = $this->teacherUser();

        $this->actingAs($this->admin())->deleteJson("/api/teachers/{$teacher->teacher_id}")->assertOk();

        $this->assertModelMissing($teacher);
        $this->assertNull(Teacher::find($teacher->teacher_id));
    }

    public function test_parent_with_students_cannot_be_deleted(): void
    {
        $parent = $this->parentUser();
        $this->student($parent);

        $this->actingAs($this->admin())->deleteJson("/api/parents/{$parent->parent_id}")->assertStatus(409);

        $this->assertModelExists($parent);
    }

    public function test_admin_deletes_a_parent_without_students(): void
    {
        $parent = $this->parentUser();

        $this->actingAs($this->admin())->deleteJson("/api/parents/{$parent->parent_id}")->assertOk();

        $this->assertModelMissing($parent);
        $this->assertNull(Guardian::find($parent->parent_id));
    }

    public function test_only_admin_manages_accounts(): void
    {
        $teacher = $this->teacherUser();
        $parent = $this->parentUser();

        foreach ([$teacher, $parent] as $user) {
            $this->actingAs($user)->getJson('/api/teachers')->assertForbidden();
            $this->actingAs($user)->postJson('/api/teachers', [])->assertForbidden();
            $this->actingAs($user)->postJson('/api/parents', [])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/parents/{$parent->parent_id}")->assertForbidden();
        }

        $this->actingAs($parent)->getJson('/api/parents')->assertForbidden();
    }

    public function test_teacher_sees_only_parents_of_students_in_own_classes(): void
    {
        $rivera = $this->teacherUser();
        $chen = $this->teacherUser();
        $mine = $this->parentUser('In my class');
        $theirs = $this->parentUser('In another class');
        $this->parentUser('No bookings');
        $this->booking($this->student($mine), $this->trialClass($rivera));
        $this->booking($this->student($theirs), $this->trialClass($chen));

        $this->actingAs($rivera)->getJson('/api/parents')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'In my class');

        $this->actingAs($this->admin())->getJson('/api/parents')->assertJsonCount(3, 'data');
    }

    public function test_admin_lists_teachers_with_their_login_details(): void
    {
        $this->teacherUser('Ms. Rivera');

        $this->actingAs($this->admin())->getJson('/api/teachers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ms. Rivera')
            ->assertJsonMissingPath('data.0.password');
    }
}
