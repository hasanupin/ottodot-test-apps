import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import AppShell from './AppShell';
import Login from './Login';

// Entry point: only mounts. Components live in their own modules so hot reload swaps them without re-running createRoot.
createRoot(document.getElementById('app')!).render(
    <StrictMode>{window.location.pathname === '/login' ? <Login /> : <AppShell />}</StrictMode>,
);
