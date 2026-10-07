import { FormEvent, useState } from 'react';
import { ApiError, request, User } from './api';
import Field, { validate } from './Field';

type FieldName = 'email' | 'password';
type FieldErrors = Partial<Record<FieldName, string>>;

const LABELS: Record<FieldName, string> = { email: 'Email', password: 'Password' };

export default function Login() {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState<FieldErrors>({});
    const [alert, setAlert] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    // On blur, and while typing once a field shows an error (so it clears as soon as it's valid).
    function check(control: HTMLInputElement | HTMLSelectElement) {
        setErrors((prev) => ({ ...prev, [control.name]: validate(control, LABELS[control.name as FieldName]) }));
    }

    async function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setAlert(null);

        const inputs = (['email', 'password'] as const).map(
            (name) => e.currentTarget.elements.namedItem(name) as HTMLInputElement,
        );
        const found: FieldErrors = {};
        for (const input of inputs) {
            found[input.name as FieldName] = validate(input, LABELS[input.name as FieldName]);
        }
        setErrors(found);

        const firstInvalid = inputs.find((input) => found[input.name as FieldName]);
        if (firstInvalid) {
            firstInvalid.focus();
            return; // invalid → no request
        }

        setSubmitting(true);
        try {
            await fetch('/sanctum/csrf-cookie', { headers: { Accept: 'application/json' } });
            await request<{ user: User }>('POST', '/api/login', { email: email.trim(), password });
            window.location.assign('/');
        } catch (err) {
            if (err instanceof ApiError && err.status === 422) {
                setErrors({ email: err.errors.email?.[0], password: err.errors.password?.[0] });
                if (!err.errors.email && !err.errors.password) setAlert(err.message);
            } else {
                // 429 throttle message, or a network failure.
                setAlert(err instanceof ApiError ? err.message : 'Network error. Please try again.');
            }
            setSubmitting(false);
        }
    }

    return (
        <main className="mx-auto mt-16 max-w-sm px-4 font-sans">
            <h1 className="mb-6 text-2xl font-semibold">Ottodot Trial Booking</h1>

            <form onSubmit={submit} noValidate className="space-y-4">
                <Field
                    label="Email"
                    name="email"
                    type="email"
                    autoComplete="email"
                    value={email}
                    error={errors.email}
                    required
                    onChange={(input) => {
                        setEmail(input.value);
                        if (errors.email) check(input);
                    }}
                    onBlur={check}
                />
                <Field
                    label="Password"
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    value={password}
                    error={errors.password}
                    required
                    onChange={(input) => {
                        setPassword(input.value);
                        if (errors.password) check(input);
                    }}
                    onBlur={check}
                />
                {alert && (
                    <p role="alert" className="text-sm text-red-700">
                        {alert}
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
        </main>
    );
}
