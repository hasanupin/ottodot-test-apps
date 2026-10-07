import { request, Role, User } from './api';

const LINKS: Record<Role, [href: string, label: string][]> = {
    admin: [
        ['/roster', 'Roster'],
        ['/trial-classes', 'Trial classes'],
        ['/teachers', 'Teachers'],
        ['/parents', 'Parents'],
        ['/students', 'Students'],
        ['/bookings', 'Bookings'],
        ['/payments', 'Payments'],
    ],
    teacher: [
        ['/roster', 'Roster'],
        ['/trial-classes', 'My classes'],
        ['/parents', 'Parents'],
        ['/students', 'Students'],
        ['/bookings', 'Bookings'],
    ],
    parent: [
        ['/', 'Book a trial'],
        ['/trial-classes', 'Trial classes'],
        ['/students', 'My children'],
        ['/bookings', 'Booking history'],
    ],
};

export default function Nav({ user }: { user: User }) {
    async function logout() {
        await request('POST', '/api/logout').catch(() => undefined);
        window.location.assign('/login');
    }

    return (
        <nav className="flex flex-wrap items-center gap-x-4 gap-y-2 border-b px-4 py-3 text-sm">
            <a href="/" className="font-semibold">
                Ottodot
            </a>
            {LINKS[user.role].map(([href, label]) => (
                <a
                    key={href}
                    href={href}
                    aria-current={window.location.pathname === href ? 'page' : undefined}
                    className="hover:underline aria-[current=page]:font-semibold aria-[current=page]:underline"
                >
                    {label}
                </a>
            ))}
            <span className="ml-auto text-gray-600">
                {user.name} ({user.role})
            </span>
            <button onClick={logout} className="rounded border px-3 py-1">
                Log out
            </button>
        </nav>
    );
}
