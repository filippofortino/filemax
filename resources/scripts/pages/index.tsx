import { Head, Link } from '@inertiajs/react';
import { Shell } from '@/components/filemax';
import { logout } from '@/routes';

export default function Index() {
    return (
        <Shell>
            <Head title="Home" />
            <main className="mx-auto flex w-full max-w-[1064px] flex-col gap-4 px-5 py-12 sm:px-8">
                <h1>Welcome</h1>
                <p className="muted">You are signed in to Filemax.</p>
                <Link
                    href={logout()}
                    method="post"
                    as="button"
                    className="self-start"
                >
                    Sign out
                </Link>
            </main>
        </Shell>
    );
}
