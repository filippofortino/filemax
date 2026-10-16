import {
    Add01Icon,
    ArrowLeft01Icon,
    ArrowRight01Icon,
    Clock01Icon,
    File01Icon,
    ListViewIcon,
    Download01Icon,
    Globe02Icon,
    Search01Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { PasswordBadge, Shell, TeamBadges } from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { bytes, date, dateTime } from '@/lib/format';
import type { Team, Transfer } from '@/lib/types';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { index, show } from '@/routes/transfers';

type Status = 'all' | 'active' | 'soon' | 'expired';
const statuses: { value: Status; label: string; dot?: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active', dot: 'bg-emerald-600' },
    { value: 'soon', label: 'Expiring soon', dot: 'bg-amber-600' },
    { value: 'expired', label: 'Expired', dot: 'bg-slate-400' },
];
const pageLinkClass = cn(
    buttonVariants({ variant: 'ghost', size: 'sm' }),
    'min-w-9 px-2.5 font-medium text-muted-foreground',
);
const pageBoundaryClass = cn(
    pageLinkClass,
    'min-h-11 gap-1.5 border-input px-3 text-foreground disabled:border-input md:min-h-9 md:border-transparent md:px-2.5 md:text-muted-foreground md:disabled:border-transparent',
);

function TransferExpiry({
    transfer,
    compact = false,
    className,
}: {
    transfer: Transfer;
    compact?: boolean;
    className?: string;
}) {
    return (
        <span
            className={cn(
                transfer.expiring_soon
                    ? 'inline-flex items-center gap-1.5 font-medium text-orange-700'
                    : 'text-muted-foreground',
                className,
            )}
            title={
                transfer.revoked_at
                    ? undefined
                    : `Expires ${dateTime(transfer.expires_at)}`
            }
        >
            {transfer.expiring_soon && (
                <HugeiconsIcon
                    icon={Clock01Icon}
                    size={15}
                    strokeWidth={2}
                    aria-hidden="true"
                />
            )}
            {transfer.revoked_at
                ? 'Deleted'
                : transfer.expiring_soon
                  ? `Expires in ${transfer.expires_in}`
                  : `${transfer.available ? 'Expires' : 'Expired'} ${
                        compact && transfer.expires_at
                            ? new Intl.DateTimeFormat('en-GB', {
                                  day: 'numeric',
                                  month: 'short',
                              }).format(new Date(transfer.expires_at))
                            : dateTime(transfer.expires_at)
                    }`}
        </span>
    );
}

type Props = {
    transfers: {
        data: Transfer[];
        links: { prev: string | null; next: string | null };
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
            links: { url: string | null; label: string; active: boolean }[];
        };
    };
    teams: Team[];
    filter: string;
    search: string;
    status: Status;
    statusCounts: Record<Status, number>;
    totals: {
        total: number;
        active: number;
    };
};
export default function Index({
    transfers,
    teams,
    filter,
    search,
    status,
    statusCounts,
    totals,
}: Props) {
    const { url } = usePage();
    const [query, setQuery] = useState(search);
    const [pending, setPending] = useState(false);
    const searchTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
        undefined,
    );
    const cancelVisit = useRef<(() => void) | undefined>(undefined);
    const cancelPending = useCallback(() => {
        clearTimeout(searchTimer.current);
        cancelVisit.current?.();
        cancelVisit.current = undefined;
    }, []);

    useEffect(() => {
        setQuery(search);
    }, [search, url]);

    useEffect(() => {
        const removeBefore = router.on('before', () =>
            clearTimeout(searchTimer.current),
        );
        window.addEventListener('popstate', cancelPending);
        return () => {
            removeBefore();
            window.removeEventListener('popstate', cancelPending);
            cancelPending();
        };
    }, [cancelPending]);

    function visitFilters(
        nextSearch: string,
        nextStatus = status,
        nextFilter = filter,
        replace = false,
    ) {
        cancelPending();
        router.get(
            index.url(),
            {
                filter: nextFilter,
                status: nextStatus,
                search: nextSearch.trim(),
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace,
                onCancelToken: (token) => {
                    cancelVisit.current = () => token.cancel();
                },
                onStart: () => setPending(true),
                onFinish: () => {
                    setPending(false);
                    cancelVisit.current = undefined;
                },
            },
        );
    }

    const navigation = {
        preserveState: true,
        preserveScroll: true,
        onBefore: cancelPending,
        onCancelToken: (token: { cancel: () => void }) => {
            cancelVisit.current = () => token.cancel();
        },
        onStart: () => setPending(true),
        onFinish: () => {
            setPending(false);
            cancelVisit.current = undefined;
        },
    };
    const audiences = [
        { id: 'all', name: 'Everyone' },
        { id: 'public', name: 'Public' },
        ...teams,
    ];

    return (
        <Shell active="transfers">
            <Head title="My transfers" />
            <main className="mx-auto w-full max-w-6xl px-5 py-7 md:p-10">
                <div className="mb-5 flex flex-col gap-1 md:flex-row md:flex-wrap md:items-baseline md:justify-between md:gap-5">
                    <h1>My transfers</h1>
                    {totals.total > 0 && (
                        <p className="text-muted-foreground">
                            {totals.total}{' '}
                            {totals.total === 1 ? 'transfer' : 'transfers'} ·{' '}
                            {totals.active} active
                        </p>
                    )}
                </div>
                {totals.total > 0 && (
                    <>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <form
                                role="search"
                                className="relative w-full md:w-90"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    visitFilters(query, status, filter, true);
                                }}
                            >
                                <label
                                    htmlFor="transfer-search"
                                    className="sr-only"
                                >
                                    Search transfers
                                </label>
                                <HugeiconsIcon
                                    icon={Search01Icon}
                                    size={18}
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-3.5 left-3 text-muted-foreground"
                                />
                                <input
                                    id="transfer-search"
                                    type="search"
                                    placeholder="Search transfers by title"
                                    autoComplete="off"
                                    maxLength={255}
                                    className="pl-10"
                                    value={query}
                                    onChange={(event) => {
                                        const value = event.target.value;
                                        setQuery(value);
                                        cancelPending();
                                        searchTimer.current = setTimeout(
                                            () =>
                                                visitFilters(
                                                    value,
                                                    status,
                                                    filter,
                                                    true,
                                                ),
                                            300,
                                        );
                                    }}
                                />
                            </form>
                            <ToggleGroup
                                aria-label="Filter by status"
                                value={[status]}
                                variant="status"
                                size="lg"
                                spacing={0.5}
                                className="w-full rounded-lg bg-muted p-1 md:w-fit"
                                onValueChange={(values) => {
                                    if (values[0] || pending) {
                                        visitFilters(
                                            query,
                                            (values[0] ?? status) as Status,
                                        );
                                    }
                                }}
                            >
                                {statuses.map((option) => (
                                    <ToggleGroupItem
                                        key={option.value}
                                        value={option.value}
                                        aria-label={`${option.label}, ${statusCounts[option.value]}`}
                                        className="h-10 min-w-0 flex-1 gap-1.5 px-1 md:h-9 md:flex-none md:gap-2 md:px-2.5"
                                    >
                                        {option.dot && (
                                            <span
                                                aria-hidden="true"
                                                className={cn(
                                                    'size-2 shrink-0 rounded-full',
                                                    option.dot,
                                                )}
                                            />
                                        )}
                                        {option.value === 'soon' ? (
                                            <>
                                                <span className="md:hidden">
                                                    Soon
                                                </span>
                                                <span className="hidden md:inline">
                                                    {option.label}
                                                </span>
                                            </>
                                        ) : (
                                            option.label
                                        )}
                                        <span className="hidden text-xs text-muted-foreground tabular-nums md:inline">
                                            {statusCounts[option.value]}
                                        </span>
                                    </ToggleGroupItem>
                                ))}
                            </ToggleGroup>
                        </div>
                        <nav
                            aria-label="Filter transfers"
                            className="-mx-5 -mt-1 mb-4 flex [scrollbar-width:none] items-center gap-2 overflow-x-auto px-5 py-1 md:mx-0 md:mt-0 md:mb-5 md:flex-wrap md:overflow-visible md:px-0 md:py-0 [&::-webkit-scrollbar]:hidden"
                        >
                            {audiences.map((audience, position) => (
                                <span key={audience.id} className="contents">
                                    {position === 2 && (
                                        <span
                                            className="mx-1 hidden h-6 border-l md:block"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <Link
                                        {...navigation}
                                        href={index({
                                            query: {
                                                filter: audience.id,
                                                status,
                                                search: query.trim(),
                                            },
                                        })}
                                        className={cn(
                                            'inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border px-4 py-1.5 text-sm font-medium whitespace-nowrap md:min-h-0 md:max-w-full md:px-3 md:wrap-anywhere md:whitespace-normal',
                                            filter === audience.id
                                                ? 'border-foreground bg-foreground text-background hover:text-background'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                        aria-current={
                                            filter === audience.id
                                                ? 'page'
                                                : undefined
                                        }
                                    >
                                        {audience.name}
                                    </Link>
                                </span>
                            ))}
                        </nav>
                    </>
                )}
                <div aria-busy={pending}>
                    <p role="status" className="sr-only">
                        {pending
                            ? 'Updating transfers'
                            : `${transfers.meta.total} matching transfers`}
                    </p>
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
                                    : 'No matching transfers'}
                            </h2>
                            <p className="max-w-sm text-base text-muted-foreground">
                                {totals.total === 0
                                    ? 'Drop some files on the New transfer page. Every link you create shows up here with its downloads and expiry.'
                                    : 'Try a different search or filter, or create a transfer to share with this audience.'}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center justify-center gap-3">
                                {totals.total > 0 && (
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setQuery('');
                                            visitFilters('', 'all', 'all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                )}
                                <Link
                                    href={home()}
                                    aria-label="Create a transfer"
                                    className={cn(buttonVariants())}
                                >
                                    <HugeiconsIcon
                                        icon={Add01Icon}
                                        size={16}
                                        aria-hidden="true"
                                    />
                                    New transfer
                                </Link>
                            </div>
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
                                    <div className="grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 md:grid-cols-6 md:gap-3 lg:grid-cols-8">
                                        <div className="col-span-2 flex min-w-0 flex-col gap-1 md:col-span-3 md:block">
                                            <h2 className="truncate font-sans text-sm leading-snug tracking-normal">
                                                {transfer.title}
                                            </h2>
                                            <p className="text-muted-foreground">
                                                {transfer.files_count}{' '}
                                                {transfer.files_count === 1
                                                    ? 'file'
                                                    : 'files'}{' '}
                                                · {bytes(transfer.total_size)} ·{' '}
                                                <span className="hidden md:inline">
                                                    {date(transfer.created_at)}
                                                </span>
                                                <TransferExpiry
                                                    transfer={transfer}
                                                    compact
                                                    className="md:hidden"
                                                />
                                            </p>
                                        </div>
                                        <span className="flex min-w-0 flex-wrap items-center gap-1.5 text-xs md:col-span-2">
                                            {transfer.visibility ===
                                            'public' ? (
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
                                            {transfer.password_protected && (
                                                <PasswordBadge compact />
                                            )}
                                        </span>
                                        <span
                                            className="flex items-center justify-end gap-1.5 text-sm md:justify-start"
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
                                        <TransferExpiry
                                            transfer={transfer}
                                            className="hidden text-sm md:col-span-6 md:inline-flex lg:col-span-2"
                                        />
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
                    {transfers.data.length > 0 && (
                        <nav
                            aria-label="Pagination"
                            className="mt-5 flex flex-wrap items-center justify-between gap-3"
                        >
                            <p className="hidden text-sm text-muted-foreground md:block">
                                Showing{' '}
                                <span className="font-semibold text-foreground tabular-nums">
                                    {transfers.meta.from}–{transfers.meta.to}
                                </span>{' '}
                                of{' '}
                                <span className="font-semibold text-foreground tabular-nums">
                                    {transfers.meta.total}
                                </span>
                            </p>
                            <div className="flex w-full items-center justify-between gap-2 md:w-auto md:flex-wrap md:justify-start md:gap-1">
                                {transfers.links.prev ? (
                                    <Link
                                        {...navigation}
                                        href={transfers.links.prev}
                                        className={pageBoundaryClass}
                                    >
                                        <HugeiconsIcon
                                            icon={ArrowLeft01Icon}
                                            size={16}
                                            aria-hidden="true"
                                        />
                                        Previous
                                    </Link>
                                ) : (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className={cn(
                                            pageBoundaryClass,
                                            'disabled:bg-transparent disabled:opacity-40',
                                        )}
                                        disabled
                                    >
                                        <HugeiconsIcon
                                            icon={ArrowLeft01Icon}
                                            size={16}
                                            aria-hidden="true"
                                        />
                                        Previous
                                    </Button>
                                )}
                                <p
                                    className="text-sm whitespace-nowrap text-muted-foreground tabular-nums md:hidden"
                                    aria-live="polite"
                                >
                                    Page{' '}
                                    <span className="font-semibold text-foreground">
                                        {transfers.meta.current_page}
                                    </span>{' '}
                                    of{' '}
                                    <span className="font-semibold text-foreground">
                                        {transfers.meta.last_page}
                                    </span>
                                </p>
                                {transfers.meta.links
                                    .slice(1, -1)
                                    .map((page, position) =>
                                        page.url ? (
                                            <Link
                                                {...navigation}
                                                key={position}
                                                href={page.url}
                                                aria-label={`Page ${page.label}`}
                                                aria-current={
                                                    page.active
                                                        ? 'page'
                                                        : undefined
                                                }
                                                className={cn(
                                                    pageLinkClass,
                                                    'hidden md:inline-flex',
                                                    page.active &&
                                                        'bg-foreground text-background hover:bg-foreground hover:text-background',
                                                )}
                                            >
                                                {page.label}
                                            </Link>
                                        ) : (
                                            <span
                                                key={position}
                                                className="hidden px-2 text-muted-foreground md:inline"
                                                aria-label="More pages"
                                            >
                                                …
                                            </span>
                                        ),
                                    )}
                                {transfers.links.next ? (
                                    <Link
                                        {...navigation}
                                        href={transfers.links.next}
                                        className={pageBoundaryClass}
                                    >
                                        Next
                                        <HugeiconsIcon
                                            icon={ArrowRight01Icon}
                                            size={16}
                                            aria-hidden="true"
                                        />
                                    </Link>
                                ) : (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className={cn(
                                            pageBoundaryClass,
                                            'disabled:bg-transparent disabled:opacity-40',
                                        )}
                                        disabled
                                    >
                                        Next
                                        <HugeiconsIcon
                                            icon={ArrowRight01Icon}
                                            size={16}
                                            aria-hidden="true"
                                        />
                                    </Button>
                                )}
                            </div>
                        </nav>
                    )}
                </div>
            </main>
        </Shell>
    );
}
