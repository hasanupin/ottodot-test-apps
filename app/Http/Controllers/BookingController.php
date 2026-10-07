<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingFilterRequest;
use App\Http\Requests\PayBookingRequest;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function index(BookingFilterRequest $request): JsonResponse
    {
        return $this->success(BookingResource::collection($this->bookings->list($request->user(), $request->validated())));
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $booking = $this->bookings->createBooking(
            $request->user()->parent_id,
            $request->integer('student_id'),
            $request->integer('trial_class_id'),
        );

        return $this->bookingResponse($booking, $booking->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureOwner($request, $booking);

        return $this->bookingResponse($booking);
    }

    /** Always 200: declined, class full and refunded are booking outcomes, not HTTP errors. */
    public function pay(PayBookingRequest $request, Booking $booking): JsonResponse
    {
        $this->ensureOwner($request, $booking);

        return $this->bookingResponse($this->bookings->pay(
            $booking,
            $request->validated('idempotency_key'),
            $request->validated('simulate', 'success'),
            (int) $request->validated('delay_ms', 0),
        ));
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->ensureOwner($request, $booking);

        return $this->bookingResponse($this->bookings->cancel($booking));
    }

    /** 404, not 403, so a parent cannot probe which booking ids exist. */
    private function ensureOwner(Request $request, Booking $booking): void
    {
        abort_unless($booking->student->parent_id === $request->user()->parent_id, 404);
    }

    private function bookingResponse(Booking $booking, int $status = 200): JsonResponse
    {
        $booking->load(['student.guardian.user', 'trialClass', 'paymentAttempts']);

        return $this->success(new BookingResource($booking), BookingResource::messageFor($booking), $status);
    }
}
