import { createInertiaApp, router } from '@inertiajs/react';
import { toast, Toaster } from '@/components/ui/toast';
import '../css/app.css';

router.on('flash', ({ detail }) => {
    if (detail.flash.toast) {
        toast.add(detail.flash.toast);
    }
});

router.on('networkError', () => {
    toast.add({
        id: 'network-error',
        type: 'error',
        title: 'Connection lost',
        description: 'Check your connection and try again.',
        timeout: 0,
        priority: 'high',
    });
    return false;
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
