<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\TestCase;

class StudentManagementTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    public function test_admin_creates_updates_and_deletes_a_student(): void
    {
        $admin = $this->admin();
        $parent = $this->parentUser('Maya Tan');

        $id = $this->actingAs($admin)
            ->postJson('/api/students', ['parent_id' => $parent->parent_id, 'name' => 'Leo', 'grade' => 3])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Leo')
            ->assertJsonPath('data.parent.name', 'Maya Tan')
            ->json('data.id');

        $this->actingAs($admin)
            ->putJson("/api/students/{$id}", ['parent_id' => $parent->parent_id, 'name' => 'Leo T.', 'grade' => null])
            ->assertOk()
            ->assertJsonPath('data.name', 'Leo T.')
            ->assertJsonPath('data.grade', null);

        $this->actingAs($admin)->deleteJson("/api/students/{$id}")->assertOk();
        $this->assertNull(Student::find($id));
    }

    public function test_create_validates_input(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/students', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id', 'name']);

        $this->actingAs($admin)->postJson('/api/students', ['parent_id' => 999, 'name' => 'X', 'grade' => 13])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id', 'grade']);
    }

    public function test_student_with_bookings_cannot_be_deleted(): void
    {
        $student = $this->student($this->parentUser());
        $this->booking($student, $this->trialClass($this->teacherUser()));

        $this->actingAs($this->admin())->deleteJson("/api/students/{$student->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertModelExists($student);
    }

    public function test_only_admin_can_write_students(): void
    {
        $parent = $this->parentUser();
        $student = $this->student($parent);

        foreach ([$parent, $this->teacherUser()] as $user) {
            $this->actingAs($user)->postJson('/api/students', ['parent_id' => $parent->parent_id, 'name' => 'X'])->assertForbidden();
            $this->actingAs($user)->putJson("/api/students/{$student->id}", ['parent_id' => $parent->parent_id, 'name' => 'X'])->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/students/{$student->id}")->assertForbidden();
        }

        $this->assertSame('Test Kid', $student->fresh()->name);
    }

    public function test_parent_lists_only_own_children(): void
    {
        $maya = $this->parentUser();
        $this->student($maya, 'Leo');
        $this->student($maya, 'Mia');
        $this->student($this->parentUser(), 'Someone else');

        $this->actingAs($maya)->getJson('/api/students')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Leo')
            ->assertJsonPath('data.1.name', 'Mia');
    }

    public function test_teacher_lists_only_students_booked_in_own_classes(): void
    {
        $rivera = $this->teacherUser();
        $parent = $this->parentUser();
        $mine = $this->student($parent, 'In my class');
        $this->booking($mine, $this->trialClass($rivera));
        $this->booking($this->student($parent, 'Other class'), $this->trialClass($this->teacherUser()));
        $this->student($parent, 'Not booked');

        $this->actingAs($rivera)->getJson('/api/students')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingAs($this->admin())->getJson('/api/students')->assertJsonCount(3, 'data');
    }
}
