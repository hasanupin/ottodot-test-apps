export type Role = 'parent' | 'teacher' | 'admin';
export type User = { id: number; name: string; email: string; role: Role };

export type Ref = { id: number; name: string };
export type BookingStatus = 'PENDING_PAYMENT' | 'CONFIRMED' | 'PAYMENT_FAILED' | 'FAILED_CLASS_FULL' | 'CANCELLED' | 'EXPIRED';
export type TrialClass = {
    id: number;
    subject: 'math' | 'science';
    title: string;
    starts_at: string;
    capacity: number;
    price_cents: number;
    teacher: Ref;
    seats_taken: number; // confirmed bookings
    seats_held: number; // hold mode: booked and awaiting payment (always 0 in first_to_pay mode)
    seats_available: number;
    is_full: boolean;
    student_booking_status?: 'CONFIRMED' | 'PENDING_PAYMENT' | null; // only with ?student_id=
};
export type PaymentRow = { status: string; amount_cents: number; failure_reason: string | null; created_at: string };
export type Booking = {
    id: number;
    status: BookingStatus;
    message: string;
    hold_expires_at: string | null; // set only for a pending booking in hold mode
    confirmed_at: string | null;
    created_at: string;
    student: Ref;
    parent: Ref;
    trial_class: { id: number; title: string; starts_at: string };
    payment_attempts: PaymentRow[];
};
export type Roster = {
    trial_class: { id: number; title: string; starts_at: string; teacher: string | null };
    capacity: number;
    confirmed_count: number;
    students: { booking_id: number; student_name: string; grade: number | null; parent_name: string | null; confirmed_at: string }[];
};

export type ApiResponse<T> = {
    success: boolean;
    message: string;
    data?: T;
    errors?: Record<string, string[]>;
};

export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]>;

    constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

// Laravel sets the XSRF-TOKEN cookie; Sanctum expects it back in this header on POST/PUT/DELETE.
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/** Calls the API and returns `data` from the standard envelope; throws ApiError otherwise. */
export async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const json = (await res.json().catch(() => ({}))) as Partial<ApiResponse<T>>;

    // Session expired or never logged in: back to the login page.
    if (res.status === 401 && window.location.pathname !== '/login') {
        window.location.assign('/login');
    }

    if (!res.ok || !json.success) {
        throw new ApiError(res.status, json.message ?? 'Request failed.', json.errors ?? {});
    }

    return json.data as T;
}
