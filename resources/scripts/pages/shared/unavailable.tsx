import { Clock01Icon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head } from '@inertiajs/react';
import { Shell } from '@/components/filemax';

export default function Unavailable({
    reason,
}: {
    reason: 'expired' | 'deleted' | 'unavailable';
}) {
    const explanation =
        reason === 'expired'
            ? 'This transfer reached the expiry chosen by its sender.'
            : reason === 'deleted'
              ? 'This transfer was removed by its sender.'
              : 'This transfer is unavailable or the link is incorrect.';
    return (
        <Shell recipient>
            <Head title="Link unavailable">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="flex flex-1 items-center justify-center px-5 py-6 md:px-10 md:pb-2">
                <section className="flex w-full max-w-lg min-w-0 flex-col items-center gap-4 text-center md:rounded-xl md:border md:bg-background md:px-10 md:py-11">
                    <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <HugeiconsIcon
                            icon={Clock01Icon}
                            size={28}
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                    </span>
                    <h1>This link is no longer available</h1>
                    <p className="max-w-sm text-base text-muted-foreground">
                        {explanation} Ask whoever sent it to share the files
                        again.
                    </p>
                </section>
            </main>
        </Shell>
    );
}
