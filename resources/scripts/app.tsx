import { createInertiaApp } from '@inertiajs/react';
import '../css/app.css';

void createInertiaApp({
    title: (title) => (title ? `${title} · Filemax` : 'Filemax'),
    pages: {
        path: './pages',
        extension: '.tsx',
    },
    progress: {
        color: '#2140E0',
    },
    strictMode: true,
});
