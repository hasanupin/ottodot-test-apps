import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        // Runs in the `node` container: listen on all interfaces, but tell the
        // browser (via public/hot + HMR) to reach it at localhost:5173.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: 'localhost' },
        watch: {
            // Bind-mounted files on macOS/Windows don't always emit change events.
            usePolling: true,
            ignored: ['**/storage/framework/views/**', '**/vendor/**', '**/node_modules/**'],
        },
    },
});
