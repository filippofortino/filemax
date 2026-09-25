import {
    Add01Icon,
    ArrowRight01Icon,
    File01Icon,
    ListViewIcon,
    Download01Icon,
    Globe02Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link } from '@inertiajs/react';
import { Shell, TeamBadges } from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import { bytes, date } from '@/lib/format';
import type { Team, Transfer } from '@/lib/types';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index, show } from '@/routes/transfers';

type Props = {
    transfers: {
        data: Transfer[];
        links: { prev: string | null; next: string | null };
        meta: { current_page: number; last_page: number };
    };
    teams: Team[];
    filter: string;
    totals: {
        total: number;
        active: number;
        expired: number;
        downloads: number;
    };
};
export default function Index({ transfers, teams, filter, totals }: Props) {
    return (
        <Shell
            active="transfers"
            headerAction={
                <Link
                    href={home()}
                    aria-label="New transfer"
                    className={cn(
                        buttonVariants({
                            size: 'icon-sm',
                            className: 'md:h-10 md:w-auto md:px-4',
                        }),
                    )}
                >
                    <HugeiconsIcon
                        icon={Add01Icon}
                        size={16}
                        aria-hidden="true"
                    />
                    <span className="hidden md:inline">New transfer</span>
                </Link>
            }
        >
            <Head title="My transfers" />
            <main className="mx-auto w-full max-w-6xl px-5 py-7 md:p-10">
                <div className="mb-5 flex flex-wrap items-baseline justify-between gap-5">
                    <h1>My transfers</h1>
                    {totals.total > 0 && (
                        <p className="text-muted-foreground">
                            {totals.total} transfers · {totals.active} active ·{' '}
                            {totals.expired} expired
                        </p>
                    )}
                </div>
                {totals.total > 0 && (
                    <nav
                        aria-label="Filter transfers"
                        className="mb-5 flex flex-wrap items-center gap-2"
                    >
                        <Link
                            href={index({ query: { filter: 'all' } })}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium',
                                filter === 'all'
                                    ? 'border-foreground bg-foreground text-background hover:text-background'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={filter === 'all' ? 'page' : undefined}
                        >
                            All transfers
                        </Link>
                        <Link
                            href={index({ query: { filter: 'public' } })}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium',
                                filter === 'public'
                                    ? 'border-foreground bg-foreground text-background hover:text-background'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={
                                filter === 'public' ? 'page' : undefined
                            }
                        >
                            Public links
                        </Link>
                        {teams.length > 0 && (
                            <span
                                className="mx-1 h-6 border-l"
                                aria-hidden="true"
                            />
                        )}
                        {teams.map((team) => (
                            <Link
                                key={team.id}
                                href={index({ query: { filter: team.id } })}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium',
                                    filter === team.id
                                        ? 'border-foreground bg-foreground text-background hover:text-background'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                                aria-current={
                                    filter === team.id ? 'page' : undefined
                                }
                            >
                                {team.name}
                            </Link>
                        ))}
                    </nav>
                )}
                {!transfers.data.length ? (
                    <div className="flex min-h-112 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-input px-6 py-16 text-center">
                        <span className="flex size-14 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <HugeiconsIcon
                                icon={ListViewIcon}
                                size={24}
                                aria-hidden="true"
                            />
                        </span>
                        <h2>
                            {totals.total === 0
                                ? 'No transfers yet'
                                : 'No transfers here yet'}
                        </h2>
                        <p className="max-w-sm text-base text-muted-foreground">
                            {totals.total === 0
                                ? 'Drop some files on the New transfer page. Every link you create shows up here with its downloads and expiry.'
                                : 'Try another filter, or create a transfer to share with this audience.'}
                        </p>
                        <Link
                            href={home()}
                            aria-label="Create a transfer"
                            className={cn(
                                buttonVariants({ className: 'mt-2' }),
                            )}
                        >
                            <HugeiconsIcon
                                icon={Add01Icon}
                                size={16}
                                aria-hidden="true"
                            />
                            New transfer
                        </Link>
                    </div>
                ) : (
                    <div className="flex flex-col border-t">
                        {transfers.data.map((transfer) => (
                            <Link
                                className={cn(
                                    'flex min-h-16 items-start gap-3 border-b py-3 hover:bg-muted/50 hover:text-foreground md:items-center md:p-3',
                                    transfer.available
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                                key={transfer.id}
                                href={show(transfer.id)}
                            >
                                <span
                                    className={cn(
                                        'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                        transfer.available
                                            ? 'bg-primary/10 text-primary'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    <HugeiconsIcon
                                        icon={File01Icon}
                                        size={20}
                                        aria-hidden="true"
                                    />
                                </span>
                                <div className="grid min-w-0 flex-1 grid-cols-1 items-center gap-2 md:grid-cols-6 md:gap-3 lg:grid-cols-8">
                                    <div className="min-w-0 md:col-span-3">
                                        <h2 className="truncate font-sans text-sm leading-snug tracking-normal">
                                            {transfer.title}
                                        </h2>
                                        <p className="text-muted-foreground">
                                            {transfer.files_count}{' '}
                                            {transfer.files_count === 1
                                                ? 'file'
                                                : 'files'}{' '}
                                            · {bytes(transfer.total_size)} ·{' '}
                                            {date(transfer.created_at)}
                                        </p>
                                    </div>
                                    <span className="min-w-0 text-xs md:col-span-2">
                                        {transfer.visibility === 'public' ? (
                                            <span className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 font-semibold text-primary">
                                                <HugeiconsIcon
                                                    icon={Globe02Icon}
                                                    size={13}
                                                    aria-hidden="true"
                                                />
                                                Public
                                            </span>
                                        ) : transfer.teams.length ? (
                                            <TeamBadges
                                                teams={transfer.teams}
                                                limit={2}
                                                variant="muted"
                                            />
                                        ) : (
                                            <span className="text-destructive">
                                                Sharing needs repair
                                            </span>
                                        )}
                                    </span>
                                    <span
                                        className="flex items-center gap-1.5 text-sm"
                                        title="Download-button clicks"
                                    >
                                        {transfer.first_opened_at ||
                                        transfer.download_count ? (
                                            <>
                                                <HugeiconsIcon
                                                    icon={Download01Icon}
                                                    size={16}
                                                    aria-hidden="true"
                                                />
                                                {transfer.download_count}
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Not opened
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-sm text-muted-foreground md:col-span-6 lg:col-span-2">
                                        {transfer.revoked_at
                                            ? 'Deleted'
                                            : `${transfer.available ? 'Expires' : 'Expired'} ${date(transfer.expires_at)}`}
                                    </span>
                                </div>
                                <HugeiconsIcon
                                    icon={ArrowRight01Icon}
                                    size={17}
                                    aria-hidden="true"
                                    className="shrink-0 text-muted-foreground"
                                />
                            </Link>
                        ))}
                    </div>
                )}
                {transfers.meta?.last_page > 1 && (
                    <nav
                        aria-label="Pagination"
                        className="mt-6 flex items-center justify-between"
                    >
                        {transfers.links.prev ? (
                            <Link
                                href={transfers.links.prev}
                                className={cn(
                                    buttonVariants({
                                        variant: 'outline',
                                    }),
                                )}
                            >
                                Previous
                            </Link>
                        ) : (
                            <Button variant="outline" disabled>
                                Previous
                            </Button>
                        )}
                        <span className="text-muted-foreground">
                            Page {transfers.meta.current_page} of{' '}
                            {transfers.meta.last_page}
                        </span>
                        {transfers.links.next ? (
                            <Link
                                href={transfers.links.next}
                                className={cn(
                                    buttonVariants({
                                        variant: 'outline',
                                    }),
                                )}
                            >
                                Next
                            </Link>
                        ) : (
                            <Button variant="outline" disabled>
                                Next
                            </Button>
                        )}
                    </nav>
                )}
            </main>
        </Shell>
    );
}
