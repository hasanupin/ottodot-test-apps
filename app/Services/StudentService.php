<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class StudentService
{
    /** Admin: all. Parent: own children. Teacher: students with a booking in the teacher's classes. */
    public function list(User $actor): Collection
    {
        return Student::with('guardian.user')
            ->when($actor->role === UserRole::Parent, fn ($q) => $q->where('parent_id', $actor->parent_id))
            ->when($actor->role === UserRole::Teacher, fn ($q) => $q->whereHas(
                'bookings.trialClass',
                fn ($c) => $c->where('teacher_id', $actor->teacher_id),
            ))
            ->orderBy('id')
            ->get();
    }

    public function create(array $data): Student
    {
        return Student::create($data)->load('guardian.user');
    }

    public function update(Student $student, array $data): Student
    {
        $student->update($data);

        return $student->load('guardian.user');
    }

    public function delete(Student $student): void
    {
        if ($student->bookings()->exists()) {
            throw new BusinessRuleException('This student has bookings and cannot be deleted.');
        }

        $student->delete();
    }
}
