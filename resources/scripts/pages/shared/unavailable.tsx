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
            <main>
                <section
                    className="recipient-card unavailable-card"
                    style={{ maxWidth: 520 }}
                >
                    <span className="round-icon">
                        <HugeiconsIcon
                            icon={Clock01Icon}
                            size={28}
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                    </span>
                    <h1>This link is no longer available</h1>
                    <p className="max-w-[380px] text-[15px] leading-[1.55] text-[#3B4552]">
                        {explanation} Ask whoever sent it to share the files
                        again.
                    </p>
                </section>
            </main>
        </Shell>
    );
}
