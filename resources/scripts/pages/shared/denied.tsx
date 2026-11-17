import { LockKeyIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, usePage } from '@inertiajs/react';
import switchAccount from '@/actions/App/Http/Controllers/SwitchAccountController';
import { Shell } from '@/components/filemax';
import { buttonVariants } from '@/components/ui/button';
import type { SharedProps } from '@/lib/types';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show } from '@/routes/shared';
import { notice } from '@/routes/verification';

type DeniedProps = {
    sender: { name: string; email: string } | null;
    token: string;
    url: string;
    organization: string | null;
    requires_verification: boolean;
};

export default function AccessDenied({
    sender,
    token,
    url,
    organization,
    requires_verification,
}: DeniedProps) {
    const { auth } = usePage<SharedProps>().props;
    const returnTo = show.url(token);
    const firstName = sender?.name.split(' ')[0];
    const subject = encodeURIComponent('Access to your Filemax transfer');
    const body = encodeURIComponent(
        `Hi ${firstName},\n\nCould you help me access the files in your Filemax transfer?\n${url}\n\nThanks!`,
    );
    return (
        <Shell recipient recipientAccount>
            <Head title="Access required">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="flex flex-1 items-center justify-center px-5 py-6 md:px-10 md:pb-2">
                <section className="flex w-full max-w-xl min-w-0 flex-col items-center gap-4 text-center md:rounded-xl md:border md:bg-background md:px-10 md:py-11">
                    <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <HugeiconsIcon
                            icon={LockKeyIcon}
                            size={28}
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                    </span>
                    <h1>You don&apos;t have access to these files</h1>
                    <p className="max-w-md text-base text-muted-foreground">
                        {sender
                            ? `${sender.name} shared this transfer with members of `
                            : 'This transfer is shared with members of '}
                        <strong className="font-semibold text-foreground">
                            {organization}
                        </strong>{' '}
                        only. You&apos;re signed in as{' '}
                        <span className="break-all">{auth.user?.email}</span>,{' '}
                        {requires_verification
                            ? 'which needs to be verified before opening members-only transfers.'
                            : 'which isn’t a member.'}
                    </p>
                    <div className="flex flex-wrap items-center justify-center gap-2.5 pt-2">
                        {requires_verification ? (
                            <Link
                                href={notice()}
                                className={cn(buttonVariants())}
                            >
                                Verify your email
                            </Link>
                        ) : (
                            sender && (
                                <a
                                    className={cn(buttonVariants())}
                                    href={`mailto:${sender.email}?subject=${subject}&body=${body}`}
                                >
                                    Ask {firstName} for access
                                </a>
                            )
                        )}
                        <Link
                            href={home()}
                            className={cn(
                                buttonVariants({ variant: 'outline' }),
                            )}
                        >
                            Go to Filemax
                        </Link>
                    </div>
                    <p className="pt-1 text-sm text-muted-foreground">
                        Signed in as the wrong account?{' '}
                        <Link
                            href={switchAccount()}
                            method="post"
                            data={{ return_to: returnTo }}
                            as="button"
                            className="font-semibold"
                        >
                            Switch account
                        </Link>
                    </p>
                </section>
            </main>
        </Shell>
    );
}
