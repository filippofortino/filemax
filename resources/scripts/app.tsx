import { createInertiaApp } from '@inertiajs/react';
import '../css/app.css';

void createInertiaApp({
    title: (title) => (title ? `${title} - Checkout` : 'Checkout'),
    pages: {
        path: './pages',
        extension: '.tsx',
    },
    progress: {
        color: '#4B5563',
    },
    strictMode: true,
});
