import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/tailwise.css',
                'resources/js/tailwise.js',
                'resources/js/dashboard.js',
                'resources/js/realtime.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    const normalizedId = id.replace(/\\/g, '/');
                    if (normalizedId.includes('/resources/tailwise/js/vendors/')) {
                        const relative = normalizedId.split('/resources/tailwise/js/vendors/')[1];
                        return `tailwise-vendor-${relative.replace(/\\/g, '-').replace(/\.js$/, '')}`;
                    }
                    if (normalizedId.includes('/resources/tailwise/js/components/')) {
                        return 'tailwise-components';
                    }
                    if (normalizedId.includes('/node_modules/')) {
                        return 'vendor';
                    }
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
