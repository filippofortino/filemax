import { Head } from '@inertiajs/react';

export default function Index() {
    return (
        <>
            <Head title="Home" />
            <main className="flex min-h-screen flex-col items-center justify-center gap-4 bg-gray-50 text-gray-900">
                <h1 className="text-4xl font-bold tracking-tight">
                    Hello, world 👋
                </h1>
                <p className="text-gray-500">
                    Inertia v3 + React + TypeScript + SSR
                </p>
            </main>
        </>
    );
}
