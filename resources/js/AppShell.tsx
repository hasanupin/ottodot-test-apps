import { useEffect, useState } from 'react';
import { request, User } from './api';
import BookingPage from './BookingPage';
import Nav from './Nav';
import RosterPage from './RosterPage';
import { BookingsPage, ParentsPage, PaymentsPage, StudentsPage, TeachersPage, TrialClassesPage } from './pages';

// No router: the server only serves /login to guests and the other paths to logged-in users.
const PAGES: Record<string, (props: { user: User }) => React.JSX.Element | null> = {
    '/': Home,
    '/roster': RosterPage,
    '/trial-classes': TrialClassesPage,
    '/teachers': TeachersPage,
    '/parents': ParentsPage,
    '/students': StudentsPage,
    '/bookings': BookingsPage,
    '/payments': PaymentsPage,
};

/** `/`: parents book; teachers and admins start on the roster. */
function Home({ user }: { user: User }) {
    const isParent = user.role === 'parent';
    useEffect(() => {
        if (!isParent) window.location.replace('/roster');
    }, [isParent]);
    return isParent ? <BookingPage /> : null;
}

export default function AppShell() {
    const [user, setUser] = useState<User | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        // A 401 here already sends the browser to /login (api.ts).
        request<{ user: User }>('GET', '/api/me').then((data) => setUser(data.user), (err: Error) => setError(err.message));
    }, []);

    if (error) return <p role="alert" className="p-4 text-sm text-red-700">{error}</p>;
    if (!user) return null;

    const Page = PAGES[window.location.pathname] ?? Home;

    return (
        <div className="font-sans">
            <Nav user={user} />
            <main className="mx-auto max-w-5xl px-4 py-6">
                <Page user={user} />
            </main>
        </div>
    );
}
