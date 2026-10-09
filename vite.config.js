import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [tailwindcss(), laravel({
        input: ['resources/css/app.css', 'resources/js/app.js'],
        refresh: true,
    })],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: { host: '127.0.0.1' },
        watch: {
            usePolling: true,
            interval: 1000,
            binaryInterval: 1000,
            ignored: ['**/vendor/**', '**/storage/**', '**/bootstrap/cache/**', '**/database-example/**', '**/.npm-cache/**'],
        },
    },
});
