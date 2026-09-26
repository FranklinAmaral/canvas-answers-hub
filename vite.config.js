import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    publicDir: 'resources/static',
    css: {
        preprocessorOptions: {
            scss: {
                silenceDeprecations: ['color-functions', 'global-builtin', 'if-function', 'import'],
            },
        },
    },
    plugins: [
        laravel({
            input: ['resources/js/graderai.js'],
            refresh: true,
        }),
    ],
});
