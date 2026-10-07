<?php

namespace Tests\Feature;

use App\Models\TrialClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsSchoolData;
use Tests\TestCase;

class TrialClassManagementTest extends TestCase
{
    use BuildsSchoolData, RefreshDatabase;

    public function test_admin_creates_a_trial_class_for_a_teacher(): void
    {
        $teacher = $this->teacherUser('Ms. Rivera');

        $this->actingAs($this->admin())
            ->postJson('/api/trial-classes', $this->payload($teacher->teacher_id))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Created.')
            ->assertJsonPath('data.title', 'Fractions')
            ->assertJsonPath('data.teacher.name', 'Ms. Rivera');

        $this->assertDatabaseHas('trial_classes', ['title' => 'Fractions', 'teacher_id' => $teacher->teacher_id, 'capacity' => 4]);
    }

    public function test_create_validates_input(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/trial-classes', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['teacher_id', 'subject', 'title', 'starts_at', 'capacity', 'price_cents']);

        $teacherId = $this->teacherUser()->teacher_id;
        $this->actingAs($admin)
            ->postJson('/api/trial-classes', $this->payload($teacherId, [
                'subject' => 'art',
                'capacity' => 5,
                'price_cents' => -1,
                'starts_at' => now()->subDay()->toDateTimeString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'capacity', 'price_cents', 'starts_at']);

        $this->actingAs($admin)->postJson('/api/trial-classes', $this->payload(999))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teacher_id']);
    }

    public function test_admin_updates_a_trial_class(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $otherTeacher = $this->teacherUser('Mr. Chen');

        $this->actingAs($this->admin())
            ->putJson("/api/trial-classes/{$class->id}", $this->payload($otherTeacher->teacher_id, ['title' => 'Volcanoes', 'capacity' => 3]))
            ->assertOk()
            ->assertJsonPath('message', 'Updated.')
            ->assertJsonPath('data.title', 'Volcanoes')
            ->assertJsonPath('data.teacher.name', 'Mr. Chen');

        $this->assertSame(3, $class->fresh()->capacity);
    }

    public function test_capacity_cannot_drop_below_confirmed_bookings(): void
    {
        $teacher = $this->teacherUser();
        $class = $this->trialClass($teacher);
        $parent = $this->parentUser();
        foreach (['A', 'B', 'C'] as $kid) {
            $this->booking($this->student($parent, $kid), $class);
        }
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson("/api/trial-classes/{$class->id}", $this->payload($teacher->teacher_id, ['capacity' => 2]))
            ->assertStatus(409)
            ->assertJsonPath('success', false);
        $this->assertSame(4, $class->fresh()->capacity);

        // Equal to the confirmed count is fine: the class is simply full.
        $this->actingAs($admin)
            ->putJson("/api/trial-classes/{$class->id}", $this->payload($teacher->teacher_id, ['capacity' => 3]))
            ->assertOk();
    }

    public function test_admin_deletes_a_class_without_bookings(): void
    {
        $class = $this->trialClass($this->teacherUser());

        $this->actingAs($this->admin())->deleteJson("/api/trial-classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Deleted.');

        $this->assertModelMissing($class);
    }

    public function test_class_with_bookings_cannot_be_deleted(): void
    {
        $class = $this->trialClass($this->teacherUser());
        $this->booking($this->student($this->parentUser()), $class);

        $this->actingAs($this->admin())->deleteJson("/api/trial-classes/{$class->id}")
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertModelExists($class);
    }

    public function test_only_admin_can_write_trial_classes(): void
    {
        $teacher = $this->teacherUser();
        $class = $this->trialClass($teacher);

        foreach ([$teacher, $this->parentUser()] as $user) {
            $this->actingAs($user)->postJson('/api/trial-classes', $this->payload($teacher->teacher_id))->assertForbidden();
            $this->actingAs($user)->putJson("/api/trial-classes/{$class->id}", $this->payload($teacher->teacher_id))->assertForbidden();
            $this->actingAs($user)->deleteJson("/api/trial-classes/{$class->id}")->assertForbidden();
        }

        $this->assertSame(1, TrialClass::count());
    }

    public function test_teacher_lists_only_own_classes_while_parent_and_admin_see_all(): void
    {
        $rivera = $this->teacherUser('Ms. Rivera');
        $chen = $this->teacherUser('Mr. Chen');
        $own = $this->trialClass($rivera, ['title' => 'Mine']);
        $this->trialClass($chen, ['title' => 'Not mine']);

        $this->actingAs($rivera)->getJson('/api/trial-classes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);

        $this->actingAs($this->parentUser())->getJson('/api/trial-classes')->assertJsonCount(2, 'data');
        $this->actingAs($this->admin())->getJson('/api/trial-classes')->assertJsonCount(2, 'data');
    }

    private function payload(int $teacherId, array $overrides = []): array
    {
        return $overrides + [
            'teacher_id' => $teacherId,
            'subject' => 'math',
            'title' => 'Fractions',
            'starts_at' => now()->addDays(3)->setTime(16, 0)->toDateTimeString(),
            'capacity' => 4,
            'price_cents' => 1500,
        ];
    }
}
