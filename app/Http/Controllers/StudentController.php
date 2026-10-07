<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRequest;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Services\StudentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(private StudentService $students) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success(StudentResource::collection($this->students->list($request->user())));
    }

    public function store(StudentRequest $request): JsonResponse
    {
        return $this->success(new StudentResource($this->students->create($request->validated())), 'Created.', 201);
    }

    public function update(StudentRequest $request, Student $student): JsonResponse
    {
        return $this->success(new StudentResource($this->students->update($student, $request->validated())), 'Updated.');
    }

    public function destroy(Student $student): JsonResponse
    {
        $this->students->delete($student);

        return $this->success(null, 'Deleted.');
    }
}
