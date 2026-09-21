import { fileURLToPath, URL } from 'node:url';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite-plus';

const checkIgnorePatterns = [
    '.agents/**',
    '.claude/**',
    '.codex/**',
    '.vite-hooks/**',
    '.vscode/**',
    '*.md',
];

export default defineConfig({
    staged: {
        '*': 'vp check --fix',
    },
    lint: {
        options: {
            typeAware: true,
            typeCheck: true,
        },
        plugins: ['eslint', 'typescript', 'unicorn', 'oxc', 'react'],
        ignorePatterns: ['vite.config.ts', ...checkIgnorePatterns],
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        useTabs: false,
        semi: true,
        singleQuote: true,
        overrides: [
            {
                files: ['**/*.yml'],
                options: {
                    tabWidth: 2,
                },
            },
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn'],
            stylesheet: 'resources/css/app.css',
        },
        sortImports: {
            groups: [
                'builtin',
                'external',
                'internal',
                'parent',
                'sibling',
                'index',
            ],
            newlinesBetween: false,
        },
        ignorePatterns: [
            ...checkIgnorePatterns,
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            'resources/js/actions/*',
            'resources/js/routes/*',
            'resources/js/wayfinder/*',
        ],
    },
    plugins: [
        laravel({
            input: ['resources/scripts/app.tsx'],
            refresh: true,
        }),
        inertia({ ssr: { sourcemap: false } }),
        react(),
        tailwindcss(),
        wayfinder({
            formVariants: true,
            path: 'resources/scripts',
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/scripts', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
