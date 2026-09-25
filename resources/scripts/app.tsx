import { createInertiaApp, router } from '@inertiajs/react';
import { toast, Toaster } from '@/components/ui/toast';
import '../css/app.css';

router.on('flash', ({ detail }) => {
    if (detail.flash.toast) {
        toast.add(detail.flash.toast);
    }
});

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
    withApp: (app) => <Toaster>{app}</Toaster>,
});
