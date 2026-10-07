<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UsersRoleConstraintTest extends TestCase
{
    use RefreshDatabase;

    private int $parentId;

    private int $teacherId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parentId = DB::table('parents')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        $this->teacherId = DB::table('teachers')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
    }

    public function test_valid_parent_teacher_and_admin_users_insert(): void
    {
        $this->insertUser(['role' => 'parent', 'parent_id' => $this->parentId]);
        $this->insertUser(['role' => 'teacher', 'teacher_id' => $this->teacherId]);
        $this->insertUser(['role' => 'admin']);

        $this->assertSame(3, DB::table('users')->count());
    }

    public function test_wrong_case_role_is_rejected(): void
    {
        $this->assertRejected(['role' => 'PARENT', 'parent_id' => $this->parentId]);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->assertRejected(['role' => 'student']);
    }

    public function test_parent_without_parent_id_is_rejected(): void
    {
        $this->assertRejected(['role' => 'parent']);
    }

    public function test_admin_with_parent_id_is_rejected(): void
    {
        $this->assertRejected(['role' => 'admin', 'parent_id' => $this->parentId]);
    }

    public function test_teacher_with_parent_id_is_rejected(): void
    {
        $this->assertRejected(['role' => 'teacher', 'teacher_id' => $this->teacherId, 'parent_id' => $this->parentId]);
    }

    public function test_two_users_cannot_share_one_parent(): void
    {
        $this->insertUser(['role' => 'parent', 'parent_id' => $this->parentId]);

        $this->assertRejected(['role' => 'parent', 'parent_id' => $this->parentId]);
    }

    public function test_parent_id_must_reference_an_existing_parent(): void
    {
        $this->assertRejected(['role' => 'parent', 'parent_id' => $this->parentId + 999]);
    }

    private function assertRejected(array $attributes): void
    {
        try {
            $this->insertUser($attributes);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Insert should have been rejected: '.json_encode($attributes));
    }

    private function insertUser(array $attributes): void
    {
        static $n = 0;
        $n++;

        DB::table('users')->insert($attributes + [
            'name' => "User $n",
            'email' => "user$n@example.com",
            'password' => 'x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
