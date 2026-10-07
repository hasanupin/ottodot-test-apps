import { useCallback, useEffect, useState } from 'react';
import { Roster, request, TrialClass, User } from './api';
import { when } from './pages';

/** Confirmed children per class. The API scopes classes by role and 403s another teacher's class. */
export default function RosterPage({ user }: { user: User }) {
    if (user.role === 'parent') {
        return (
            <p className="text-sm text-gray-700">
                The roster is for teachers and admins.{' '}
                <a href="/" className="underline">
                    Back to booking
                </a>
            </p>
        );
    }

    return <RosterView />;
}

function RosterView() {
    const [classes, setClasses] = useState<TrialClass[]>([]);
    const [classId, setClassId] = useState(() => new URLSearchParams(window.location.search).get('class') ?? '');
    const [roster, setRoster] = useState<Roster | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [autoRefresh, setAutoRefresh] = useState(false);

    useEffect(() => {
        request<TrialClass[]>('GET', '/api/trial-classes').then(setClasses, (err: Error) => setError(err.message));
    }, []);

    const load = useCallback(() => {
        if (!classId) return;
        request<Roster>('GET', `/api/trial-classes/${classId}/roster`).then(
            (r) => {
                setRoster(r);
                setError(null);
            },
            (err: Error) => {
                setRoster(null);
                setError(err.message);
            },
        );
    }, [classId]);

    useEffect(load, [load]);

    useEffect(() => {
        if (!autoRefresh) return;
        const timer = setInterval(load, 3000);
        return () => clearInterval(timer);
    }, [autoRefresh, load]);

    function select(id: string) {
        setClassId(id);
        setRoster(null);
        window.history.replaceState(null, '', id ? `?class=${id}` : window.location.pathname);
    }

    return (
        <section className="space-y-4">
            <h1 className="text-xl font-semibold">Roster</h1>
            <div className="flex flex-wrap items-center gap-3 text-sm">
                <label>
                    <span className="mr-2">Class</span>
                    <select value={classId} onChange={(e) => select(e.target.value)} className="rounded border px-2 py-1">
                        <option value="">Select…</option>
                        {classes.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.title}
                            </option>
                        ))}
                    </select>
                </label>
                <button onClick={load} disabled={!classId} className="rounded border px-3 py-1 disabled:opacity-50">
                    Refresh
                </button>
                <label className="flex items-center gap-1">
                    <input type="checkbox" checked={autoRefresh} onChange={(e) => setAutoRefresh(e.target.checked)} />
                    Auto-refresh (3s)
                </label>
            </div>

            {error && <p role="alert" className="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{error}</p>}

            {roster && (
                <div className="space-y-3">
                    <div className="text-sm">
                        <p className="font-medium">{roster.trial_class.title}</p>
                        <p className="text-gray-700">
                            {roster.trial_class.teacher} · {when(roster.trial_class.starts_at)}
                        </p>
                        <p className="font-medium">
                            {roster.confirmed_count} / {roster.capacity} confirmed
                        </p>
                    </div>
                    {roster.students.length === 0 ? (
                        <p className="text-sm text-gray-600">No confirmed students yet.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b">
                                    <tr>
                                        {['#', 'Student', 'Grade', 'Parent', 'Confirmed at'].map((h) => (
                                            <th key={h} className="py-2 pr-4 font-medium">
                                                {h}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {roster.students.map((s, i) => (
                                        <tr key={s.booking_id} className="border-b last:border-0">
                                            <td className="py-2 pr-4">{i + 1}</td>
                                            <td className="py-2 pr-4">{s.student_name}</td>
                                            <td className="py-2 pr-4">{s.grade ?? '—'}</td>
                                            <td className="py-2 pr-4">{s.parent_name ?? '—'}</td>
                                            <td className="py-2 pr-4">{when(s.confirmed_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
