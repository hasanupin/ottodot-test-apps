<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Guardian;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success(AccountResource::collection($this->accounts->listParents($request->user())));
    }

    public function store(AccountRequest $request): JsonResponse
    {
        return $this->success(new AccountResource($this->accounts->create(UserRole::Parent, $request->validated())), 'Created.', 201);
    }

    public function update(AccountRequest $request, Guardian $guardian): JsonResponse
    {
        return $this->success(new AccountResource($this->accounts->update($guardian, $request->validated())), 'Updated.');
    }

    public function destroy(Guardian $guardian): JsonResponse
    {
        $this->accounts->delete($guardian);

        return $this->success(null, 'Deleted.');
    }
}
