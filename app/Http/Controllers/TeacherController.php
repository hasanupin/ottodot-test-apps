<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Teacher;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;

class TeacherController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(): JsonResponse
    {
        return $this->success(AccountResource::collection($this->accounts->listTeachers()));
    }

    public function store(AccountRequest $request): JsonResponse
    {
        return $this->success(new AccountResource($this->accounts->create(UserRole::Teacher, $request->validated())), 'Created.', 201);
    }

    public function update(AccountRequest $request, Teacher $teacher): JsonResponse
    {
        return $this->success(new AccountResource($this->accounts->update($teacher, $request->validated())), 'Updated.');
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $this->accounts->delete($teacher);

        return $this->success(null, 'Deleted.');
    }
}
