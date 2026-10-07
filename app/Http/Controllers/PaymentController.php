<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentFilterRequest;
use App\Http\Resources\PaymentAttemptResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function index(PaymentFilterRequest $request): JsonResponse
    {
        return $this->success(PaymentAttemptResource::collection($this->payments->list($request->validated())));
    }
}
