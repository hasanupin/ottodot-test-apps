import { FormEvent, useState } from 'react';

type User = { name: string; email: string };

export default function Login() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [user, setUser] = useState<User | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function submit(e: FormEvent) {
        e.preventDefault();
        setSubmitting(true);
        setError(null);

        try {
            const res = await fetch('/api/login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ email, password }),
            });
            const body = await res.json();

            if (res.ok) {
                setUser(body.user);
                setPassword('');
            } else {
                // 422 → first validation message; 429 → throttle message.
                setError(Object.values<string[]>(body.errors ?? {})[0]?.[0] ?? body.message ?? 'Login failed.');
            }
        } catch {
            setError('Network error. Please try again.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <main className="mx-auto mt-16 max-w-sm px-4 font-sans">
            <h1 className="mb-6 text-2xl font-semibold">Ottodot Trial Booking</h1>

            {user ? (
                <div className="space-y-4">
                    <p>
                        Logged in as <strong>{user.name}</strong> ({user.email})
                    </p>
                    <button onClick={() => setUser(null)} className="rounded border px-4 py-2">
                        Log out
                    </button>
                </div>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    <label className="block">
                        <span className="text-sm">Email</span>
                        <input
                            type="email"
                            required
                            autoComplete="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            className="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    <label className="block">
                        <span className="text-sm">Password</span>
                        <input
                            type="password"
                            required
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="mt-1 w-full rounded border px-3 py-2"
                        />
                    </label>
                    {error && (
                        <p role="alert" className="text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <button
                        type="submit"
                        disabled={submitting}
                        className="w-full rounded bg-black px-4 py-2 text-white disabled:opacity-50"
                    >
                        {submitting ? 'Logging in…' : 'Log in'}
                    </button>
                </form>
            )}
        </main>
    );
}
