import { LockKeyIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, usePage } from '@inertiajs/react';
import switchAccount from '@/actions/App/Http/Controllers/SwitchAccountController';
import { Shell, TeamBadges } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import type { SharedProps, Team } from '@/lib/types';
import { home } from '@/routes';
import { show } from '@/routes/shared';
import { notice } from '@/routes/verification';

type DeniedProps = {
    sender: { name: string; email: string };
    token: string;
    url: string;
    own_teams: Team[];
    requires_verification: boolean;
};

export default function AccessDenied({
    sender,
    token,
    url,
    own_teams,
    requires_verification,
}: DeniedProps) {
    const { auth } = usePage<SharedProps>().props;
    const returnTo = show.url(token);
    const firstName = sender.name.split(' ')[0];
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
                        {sender.name} shared this transfer with selected teams.
                        You&apos;re signed in as{' '}
                        <span className="break-all">{auth.user?.email}</span>,{' '}
                        {requires_verification
                            ? 'which needs to be verified before opening team transfers.'
                            : 'which doesn’t currently have access.'}
                    </p>
                    <div className="flex flex-wrap items-center justify-center gap-2 rounded-lg bg-muted px-3.5 py-2.5">
                        <span className="text-sm text-muted-foreground">
                            Your teams:
                        </span>
                        {own_teams.length ? (
                            <TeamBadges teams={own_teams} />
                        ) : (
                            <span className="text-sm text-muted-foreground">
                                No teams assigned
                            </span>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center justify-center gap-2.5 pt-2">
                        {requires_verification ? (
                            <Button asChild>
                                <Link href={notice()}>Verify your email</Link>
                            </Button>
                        ) : (
                            <Button asChild>
                                <a
                                    href={`mailto:${sender.email}?subject=${subject}&body=${body}`}
                                >
                                    Ask {firstName} for access
                                </a>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={home()}>Go to Filemax</Link>
                        </Button>
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
