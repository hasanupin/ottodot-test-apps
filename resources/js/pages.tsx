import { ReactNode, useEffect, useState } from 'react';
import { Booking, PaymentRow, Ref, request, TrialClass, User } from './api';
import { Option } from './Field';
import ResourcePage from './ResourcePage';

type Account = { id: number; user_id: number; name: string; email: string; students?: { id: number; name: string }[] };
type Student = { id: number; name: string; grade: number | null; parent: Ref };
type Payment = PaymentRow & {
    id: number;
    booking: { id: number; status: string; student: Ref; trial_class: { id: number; title: string } };
};

const BOOKING_STATUSES = ['PENDING_PAYMENT', 'CONFIRMED', 'PAYMENT_FAILED', 'FAILED_CLASS_FULL', 'CANCELLED', 'EXPIRED'];
const PAYMENT_STATUSES = ['PENDING', 'SUCCEEDED', 'FAILED', 'REFUNDED'];

// Shown in UTC (the app timezone), matching the "Starts at (UTC)" form field.
export const when = (iso: string | null) => (iso ? `${new Date(iso).toLocaleString(undefined, { timeZone: 'UTC' })} UTC` : '—');
export const money = (cents: number) => `$${(cents / 100).toFixed(2)}`;
const toOptions = (items: Ref[]): Option[] => items.map((i) => ({ value: String(i.id), label: i.name }));

/** Loads a list for a select; skipped (empty) when the role may not call it. */
function useOptions(url: string, enabled: boolean): Option[] {
    const [options, setOptions] = useState<Option[]>([]);
    useEffect(() => {
        if (!enabled) return;
        request<Ref[]>('GET', url).then((items) => setOptions(toOptions(items)), () => setOptions([]));
    }, [url, enabled]);
    return options;
}

export function NotAvailable() {
    return <p className="text-sm text-gray-700">Not available for your role.</p>;
}

export function TrialClassesPage({ user }: { user: User }) {
    const isAdmin = user.role === 'admin';
    const teachers = useOptions('/api/teachers', isAdmin);

    return (
        <ResourcePage<TrialClass>
            title={user.role === 'teacher' ? 'My classes' : 'Trial classes'}
            noun="trial class"
            endpoint="/api/trial-classes"
            canEdit={isAdmin}
            emptyText="No trial classes yet."
            columns={[
                { label: 'Title', render: (c) => c.title },
                { label: 'Subject', render: (c) => c.subject },
                { label: 'Teacher', render: (c) => c.teacher.name },
                { label: 'Starts', render: (c) => when(c.starts_at) },
                { label: 'Booked', render: (c) => `${c.seats_taken} / ${c.capacity}${c.is_full ? ' (full)' : ''}` },
                { label: 'Price', render: (c) => money(c.price_cents) },
            ]}
            fields={[
                { name: 'title', label: 'Title', required: true, value: (c) => c.title },
                {
                    name: 'subject',
                    label: 'Subject',
                    required: true,
                    options: [
                        { value: 'math', label: 'Math' },
                        { value: 'science', label: 'Science' },
                    ],
                    value: (c) => c.subject,
                },
                { name: 'teacher_id', label: 'Teacher', required: true, options: teachers, numeric: true, value: (c) => String(c.teacher.id) },
                // ponytail: entered and shown in UTC (the app timezone) so edits round-trip; add tz handling if users span zones.
                { name: 'starts_at', label: 'Starts at (UTC)', type: 'datetime-local', required: true, value: (c) => c.starts_at.slice(0, 16) },
                { name: 'capacity', label: 'Capacity', type: 'number', min: 1, max: 4, required: true, numeric: true, value: (c) => String(c.capacity) },
                { name: 'price_cents', label: 'Price (cents)', type: 'number', min: 0, required: true, numeric: true, value: (c) => String(c.price_cents) },
            ]}
        />
    );
}

function accountFields<T extends Account>() {
    return [
        { name: 'name', label: 'Name', required: true, value: (a: T) => a.name },
        { name: 'email', label: 'Email', type: 'email' as const, required: true, value: (a: T) => a.email },
        { name: 'password', label: 'Password', type: 'password' as const, required: 'create' as const, minLength: 8 },
    ];
}

export function TeachersPage({ user }: { user: User }) {
    if (user.role !== 'admin') return <NotAvailable />;

    return (
        <ResourcePage<Account>
            title="Teachers"
            noun="teacher"
            endpoint="/api/teachers"
            canEdit
            emptyText="No teachers yet."
            columns={[
                { label: 'Name', render: (a) => a.name },
                { label: 'Email', render: (a) => a.email },
            ]}
            fields={accountFields<Account>()}
        />
    );
}

export function ParentsPage({ user }: { user: User }) {
    if (user.role === 'parent') return <NotAvailable />;

    return (
        <ResourcePage<Account>
            title="Parents"
            noun="parent"
            endpoint="/api/parents"
            canEdit={user.role === 'admin'}
            emptyText={user.role === 'teacher' ? 'No parents with children in your classes yet.' : 'No parents yet.'}
            columns={[
                { label: 'Name', render: (a) => a.name },
                { label: 'Email', render: (a) => a.email },
                { label: 'Children', render: (a) => a.students?.map((s) => s.name).join(', ') || '—' },
            ]}
            fields={accountFields<Account>()}
        />
    );
}

export function StudentsPage({ user }: { user: User }) {
    const isAdmin = user.role === 'admin';
    const parents = useOptions('/api/parents', isAdmin);

    return (
        <ResourcePage<Student>
            title={user.role === 'parent' ? 'My children' : 'Students'}
            noun="student"
            endpoint="/api/students"
            canEdit={isAdmin}
            emptyText={user.role === 'teacher' ? 'No students booked in your classes yet.' : 'No students yet.'}
            columns={[
                { label: 'Name', render: (s) => s.name },
                { label: 'Grade', render: (s) => s.grade ?? '—' },
                { label: 'Parent', render: (s) => s.parent.name },
            ]}
            fields={[
                { name: 'name', label: 'Name', required: true, value: (s) => s.name },
                { name: 'grade', label: 'Grade', type: 'number', min: 1, max: 12, numeric: true, value: (s) => (s.grade === null ? '' : String(s.grade)) },
                { name: 'parent_id', label: 'Parent', required: true, options: parents, numeric: true, value: (s) => String(s.parent.id) },
            ]}
        />
    );
}

/** Read-only filtered list. Filters live in the URL query, so a filtered view can be reloaded or shared. */
function useQueryFilters(keys: string[]) {
    const [filters, setFilters] = useState<Record<string, string>>(() => {
        const q = new URLSearchParams(window.location.search);
        return Object.fromEntries(keys.map((k) => [k, q.get(k) ?? '']));
    });

    function set(key: string, value: string) {
        const next = { ...filters, [key]: value };
        setFilters(next);
        const q = new URLSearchParams(Object.entries(next).filter(([, v]) => v !== ''));
        window.history.replaceState(null, '', q.toString() ? `?${q}` : window.location.pathname);
    }

    const query = new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== '')).toString();
    return { filters, set, query };
}

function FilterSelect({ label, value, options, onChange }: { label: string; value: string; options: Option[]; onChange: (v: string) => void }) {
    return (
        <label className="text-sm">
            <span className="mr-1">{label}</span>
            <select value={value} onChange={(e) => onChange(e.target.value)} className="rounded border px-2 py-1">
                <option value="">All</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function useList<T>(url: string) {
    const [rows, setRows] = useState<T[] | null>(null);
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        setRows(null);
        request<T[]>('GET', url).then(setRows, (err: Error) => setError(err.message));
    }, [url]);
    return { rows, error };
}

function Table<T extends { id: number }>({ rows, error, empty, head, cells }: {
    rows: T[] | null;
    error: string | null;
    empty: string;
    head: string[];
    cells: (row: T) => ReactNode[];
}) {
    if (error) return <p role="alert" className="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{error}</p>;
    if (rows === null) return <p className="text-sm text-gray-600">Loading…</p>;
    if (rows.length === 0) return <p className="text-sm text-gray-600">{empty}</p>;

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
                <thead className="border-b">
                    <tr>{head.map((h) => <th key={h} className="py-2 pr-4 font-medium">{h}</th>)}</tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="border-b last:border-0">
                            {cells(row).map((cell, i) => <td key={i} className="py-2 pr-4">{cell}</td>)}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function BookingsPage({ user }: { user: User }) {
    const isAdmin = user.role === 'admin';
    const { filters, set, query } = useQueryFilters(['status', 'trial_class_id', 'student_id', 'parent_id']);
    const { rows, error } = useList<Booking>(`/api/bookings${query ? `?${query}` : ''}`);
    const classes = useOptions('/api/trial-classes', true);
    const students = useOptions('/api/students', user.role !== 'teacher');
    const parents = useOptions('/api/parents', isAdmin);

    return (
        <section className="space-y-4">
            <h1 className="text-xl font-semibold">{user.role === 'parent' ? 'Booking history' : 'Bookings'}</h1>
            <div className="flex flex-wrap gap-3">
                <FilterSelect label="Status" value={filters.status} options={BOOKING_STATUSES.map((s) => ({ value: s, label: s }))} onChange={(v) => set('status', v)} />
                <FilterSelect label="Class" value={filters.trial_class_id} options={classes} onChange={(v) => set('trial_class_id', v)} />
                {user.role !== 'teacher' && (
                    <FilterSelect label={user.role === 'parent' ? 'Child' : 'Student'} value={filters.student_id} options={students} onChange={(v) => set('student_id', v)} />
                )}
                {isAdmin && <FilterSelect label="Parent" value={filters.parent_id} options={parents} onChange={(v) => set('parent_id', v)} />}
            </div>
            <Table
                rows={rows}
                error={error}
                empty="No bookings match."
                head={['#', 'Student', 'Parent', 'Class', 'Status', 'Payment', 'Booked', 'Confirmed']}
                cells={(b) => [
                    b.id,
                    b.student.name,
                    b.parent.name,
                    b.trial_class.title,
                    b.status,
                    b.payment_attempts.map((p) => p.status + (p.failure_reason ? ` (${p.failure_reason})` : '')).join(', ') || '—',
                    when(b.created_at),
                    when(b.confirmed_at),
                ]}
            />
        </section>
    );
}

export function PaymentsPage({ user }: { user: User }) {
    return user.role === 'admin' ? <PaymentsTable /> : <NotAvailable />;
}

function PaymentsTable() {
    const { filters, set, query } = useQueryFilters(['status']);
    const { rows, error } = useList<Payment>(`/api/payments${query ? `?${query}` : ''}`);

    return (
        <section className="space-y-4">
            <h1 className="text-xl font-semibold">Payments</h1>
            <FilterSelect label="Status" value={filters.status} options={PAYMENT_STATUSES.map((s) => ({ value: s, label: s }))} onChange={(v) => set('status', v)} />
            <Table
                rows={rows}
                error={error}
                empty="No payments match."
                head={['#', 'Booking', 'Student', 'Class', 'Status', 'Amount', 'Reason', 'At']}
                cells={(p) => [p.id, `#${p.booking.id} (${p.booking.status})`, p.booking.student.name, p.booking.trial_class.title, p.status, money(p.amount_cents), p.failure_reason ?? '—', when(p.created_at)]}
            />
        </section>
    );
}
