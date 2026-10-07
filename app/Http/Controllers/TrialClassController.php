<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\TrialClassRequest;
use App\Http\Resources\TrialClassResource;
use App\Models\TrialClass;
use App\Services\BookingService;
use App\Services\TrialClassService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialClassController extends Controller
{
    public function __construct(private TrialClassService $classes, private BookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $studentId = $request->has('student_id') ? $request->integer('student_id') : null;

        return $this->success(TrialClassResource::collection($this->classes->list($request->user(), $studentId)));
    }

    /** Confirmed children only. A teacher sees own classes; admin sees all (parents stopped by role middleware). */
    public function roster(Request $request, TrialClass $trialClass): JsonResponse
    {
        $user = $request->user();
        abort_if($user->role === UserRole::Teacher && $user->teacher_id !== $trialClass->teacher_id, 403, 'This is not your class.');

        $roster = $this->bookings->roster($trialClass);
        $trialClass->load('teacher.user');

        return $this->success([
            'trial_class' => [
                'id' => $trialClass->id,
                'title' => $trialClass->title,
                'starts_at' => $trialClass->starts_at->toIso8601String(),
                'teacher' => $trialClass->teacher->user?->name,
            ],
            'capacity' => $trialClass->capacity,
            'confirmed_count' => $roster->count(),
            'students' => $roster->map(fn ($b) => [
                'booking_id' => $b->id,
                'student_name' => $b->student->name,
                'grade' => $b->student->grade,
                'parent_name' => $b->student->guardian->user?->name,
                'confirmed_at' => $b->confirmed_at->toIso8601String(),
            ]),
        ]);
    }

    public function store(TrialClassRequest $request): JsonResponse
    {
        return $this->success(new TrialClassResource($this->classes->create($request->validated())), 'Created.', 201);
    }

    public function update(TrialClassRequest $request, TrialClass $trialClass): JsonResponse
    {
        return $this->success(new TrialClassResource($this->classes->update($trialClass, $request->validated())), 'Updated.');
    }

    public function destroy(TrialClass $trialClass): JsonResponse
    {
        $this->classes->delete($trialClass);

        return $this->success(null, 'Deleted.');
    }
}
