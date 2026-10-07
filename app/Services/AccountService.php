<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\BusinessRuleException;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Teachers and parents. Each is a role row (teachers / parents) plus a users row holding name, email and password,
 * linked by users.teacher_id / users.parent_id. Both rows are written together in one transaction.
 */
class AccountService
{
    public function listTeachers(): Collection
    {
        return Teacher::with('user')->orderBy('id')->get();
    }

    /** Admin: every parent. Teacher: parents of students that have a booking in the teacher's classes. */
    public function listParents(User $actor): Collection
    {
        return Guardian::with(['user', 'students'])
            ->when($actor->role === UserRole::Teacher, fn ($q) => $q->whereHas(
                'students.bookings.trialClass',
                fn ($c) => $c->where('teacher_id', $actor->teacher_id),
            ))
            ->orderBy('id')
            ->get();
    }

    public function create(UserRole $role, array $data): Teacher|Guardian
    {
        return DB::transaction(function () use ($role, $data) {
            $account = $role === UserRole::Teacher ? Teacher::create() : Guardian::create();

            User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],   // hashed by the User cast
                'role' => $role,
                $role === UserRole::Teacher ? 'teacher_id' : 'parent_id' => $account->id,
            ]);

            return $account->load('user');
        });
    }

    public function update(Teacher|Guardian $account, array $data): Teacher|Guardian
    {
        $changes = ['name' => $data['name'], 'email' => $data['email']];
        if (! empty($data['password'])) {
            $changes['password'] = $data['password'];
        }

        $account->user->update($changes);

        return $account->load('user');
    }

    public function delete(Teacher|Guardian $account): void
    {
        if ($account instanceof Teacher && $account->trialClasses()->exists()) {
            throw new BusinessRuleException('This teacher still has trial classes and cannot be deleted.');
        }
        if ($account instanceof Guardian && $account->students()->exists()) {
            throw new BusinessRuleException('This parent still has students and cannot be deleted.');
        }

        DB::transaction(function () use ($account) {
            $account->user?->delete();   // users references the role row, so it goes first
            $account->delete();
        });
    }
}
