import { useCallback, useEffect, useRef, useState } from 'react';
import { Booking, BookingStatus, Ref, request, TrialClass } from './api';
import { money, when } from './pages';

const ERROR_BOX = 'rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800';
const BUTTON = 'rounded border px-3 py-1 text-sm disabled:cursor-not-allowed disabled:opacity-50';

const STATUS_BOX: Record<BookingStatus, string> = {
    CONFIRMED: 'border-green-300 bg-green-50 text-green-900',
    PENDING_PAYMENT: 'border-gray-300 bg-gray-50 text-gray-900',
    PAYMENT_FAILED: 'border-red-300 bg-red-50 text-red-900',
    FAILED_CLASS_FULL: 'border-red-300 bg-red-50 text-red-900',
    CANCELLED: 'border-amber-300 bg-amber-50 text-amber-900',
    EXPIRED: 'border-amber-300 bg-amber-50 text-amber-900',
};

/** Seconds until `iso`, ticking every second; null when there is no deadline (first-to-pay mode). */
function useSecondsLeft(iso: string | null): number | null {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        if (!iso) return;
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, [iso]);
    return iso ? Math.max(0, Math.ceil((Date.parse(iso) - now) / 1000)) : null;
}

/** Parent flow: child → class → mock payment → result. Every rule is decided by the API; buttons are only hints. */
export default function BookingPage() {
    const [children, setChildren] = useState<Ref[] | null>(null);
    const [childId, setChildId] = useState('');
    const [classes, setClasses] = useState<TrialClass[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [checkout, setCheckout] = useState<{ booking: Booking; trialClass: TrialClass } | null>(null);

    useEffect(() => {
        request<Ref[]>('GET', '/api/students').then((kids) => {
            setChildren(kids);
            if (kids.length > 0) setChildId(String(kids[0].id));
        }, (err: Error) => setError(err.message));
    }, []);

    const loadClasses = useCallback(() => {
        if (!childId) return;
        setClasses(null);
        request<TrialClass[]>('GET', `/api/trial-classes?student_id=${childId}`).then(setClasses, (err: Error) => setError(err.message));
    }, [childId]);

    useEffect(loadClasses, [loadClasses]);

    function book(trialClass: TrialClass) {
        setError(null);
        request<Booking>('POST', '/api/bookings', { student_id: Number(childId), trial_class_id: trialClass.id }).then(
            (booking) => setCheckout({ booking, trialClass }),
            (err: Error) => setError(err.message),
        );
    }

    if (checkout) {
        return (
            <PaymentPanel
                {...checkout}
                onDone={() => {
                    setCheckout(null);
                    loadClasses();
                }}
            />
        );
    }

    return (
        <section className="space-y-4">
            <h1 className="text-xl font-semibold">Book a trial class</h1>

            {children?.length === 0 && <p className="text-sm text-gray-700">No children on your account yet.</p>}
            {children && children.length > 0 && (
                <label className="block text-sm">
                    <span className="mr-2">1. Choose a child</span>
                    <select value={childId} onChange={(e) => setChildId(e.target.value)} className="rounded border px-2 py-1">
                        {children.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}

            {error && <p role="alert" className={ERROR_BOX}>{error}</p>}

            {childId && (
                <div className="space-y-2">
                    <div className="flex items-center gap-3">
                        <h2 className="text-sm">2. Choose a trial class</h2>
                        <button onClick={loadClasses} className={BUTTON}>
                            Refresh
                        </button>
                    </div>
                    {classes === null && <p className="text-sm text-gray-600">Loading…</p>}
                    {classes?.length === 0 && <p className="text-sm text-gray-600">No trial classes available.</p>}
                    <ul className="space-y-2">
                        {classes?.map((c) => (
                            <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 rounded border p-3">
                                <div className="text-sm">
                                    <p className="font-medium">{c.title}</p>
                                    <p className="text-gray-700">
                                        {c.subject} · {c.teacher.name} · {when(c.starts_at)} · {money(c.price_cents)}
                                    </p>
                                    <p className="text-gray-700">
                                        {c.seats_available} of {c.capacity} seats left
                                    </p>
                                </div>
                                <BookButton trialClass={c} onBook={() => book(c)} />
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

function BookButton({ trialClass, onBook }: { trialClass: TrialClass; onBook: () => void }) {
    if (trialClass.student_booking_status === 'CONFIRMED') return <button disabled className={BUTTON}>Already booked</button>;
    // The API returns the existing pending booking, so this simply resumes it.
    if (trialClass.student_booking_status === 'PENDING_PAYMENT') return <button onClick={onBook} className={BUTTON}>Continue to payment</button>;
    if (trialClass.is_full) return <button disabled className={BUTTON}>Full</button>;
    return <button onClick={onBook} className={BUTTON}>Book</button>;
}

function PaymentPanel({ booking, trialClass, onDone }: { booking: Booking; trialClass: TrialClass; onDone: () => void }) {
    // One key per panel, kept across re-renders and network retries, so a retried request can't charge twice.
    const idempotencyKey = useRef('');
    if (!idempotencyKey.current) idempotencyKey.current = crypto.randomUUID();

    const [delayMs, setDelayMs] = useState('0');
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<Booking | null>(null);
    const secondsLeft = useSecondsLeft(booking.hold_expires_at);

    function pay(simulate: 'success' | 'decline') {
        setProcessing(true);
        setError(null);
        request<Booking>('POST', `/api/bookings/${booking.id}/pay`, {
            idempotency_key: idempotencyKey.current,
            simulate,
            delay_ms: Number(delayMs) || 0,
        }).then(setResult, (err: Error) => {
            setError(err.message);
            setProcessing(false); // retry reuses the same key
        });
    }

    if (result) {
        return (
            <section className="space-y-4">
                <h1 className="text-xl font-semibold">Booking #{result.id}</h1>
                <div role="status" className={`rounded border px-3 py-2 text-sm ${STATUS_BOX[result.status]}`}>
                    <p className="font-medium">{result.status}</p>
                    <p>{result.message}</p>
                </div>
                <div className="text-sm">
                    <h2 className="font-medium">Payment attempts</h2>
                    {result.payment_attempts.length === 0 ? (
                        <p className="text-gray-700">None (no charge was made).</p>
                    ) : (
                        <ul className="list-inside list-disc">
                            {result.payment_attempts.map((p, i) => (
                                <li key={i}>
                                    {p.status} · {money(p.amount_cents)}
                                    {p.failure_reason && ` · ${p.failure_reason}`}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                <button onClick={onDone} className={BUTTON}>
                    Book another class
                </button>
            </section>
        );
    }

    return (
        <section className="space-y-4">
            <h1 className="text-xl font-semibold">3. Payment (mock)</h1>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt className="text-gray-600">Booking</dt>
                <dd>#{booking.id}</dd>
                <dt className="text-gray-600">Class</dt>
                <dd>
                    {trialClass.title} · {when(trialClass.starts_at)}
                </dd>
                <dt className="text-gray-600">Child</dt>
                <dd>{booking.student.name}</dd>
                <dt className="text-gray-600">Price</dt>
                <dd>{money(trialClass.price_cents)}</dd>
            </dl>
            <p className="text-sm text-gray-700">
                {booking.message}
                {secondsLeft === null && ' The seat goes to whoever pays first.'}
            </p>
            {secondsLeft !== null && (
                // The backend decides; the countdown only tells the parent how long the seat is reserved.
                <p role="timer" className={`text-sm font-medium ${secondsLeft > 0 ? 'text-gray-900' : 'text-amber-800'}`}>
                    {secondsLeft > 0
                        ? `Seat held for you: ${Math.floor(secondsLeft / 60)}:${String(secondsLeft % 60).padStart(2, '0')}`
                        : 'Your hold has expired, so the seat may have gone to another parent.'}
                </p>
            )}

            <label className="block text-sm">
                <span className="mr-2">Gateway delay (ms)</span>
                <input
                    type="number"
                    min={0}
                    max={5000}
                    value={delayMs}
                    onChange={(e) => setDelayMs(e.target.value)}
                    disabled={processing}
                    className="w-24 rounded border px-2 py-1"
                />
            </label>

            {error && <p role="alert" className={ERROR_BOX}>{error}</p>}

            <div className="flex flex-wrap items-center gap-3">
                <button onClick={() => pay('success')} disabled={processing} className={BUTTON}>
                    Pay (success)
                </button>
                <button onClick={() => pay('decline')} disabled={processing} className={BUTTON}>
                    Pay (decline)
                </button>
                <button onClick={onDone} disabled={processing} className="text-sm underline disabled:opacity-50">
                    Back to classes
                </button>
                {processing && <span className="text-sm text-gray-700">Processing payment…</span>}
            </div>
        </section>
    );
}
