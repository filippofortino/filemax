import {
    Add01Icon,
    ArrowRight01Icon,
    Clock01Icon,
    PackageReceiveIcon,
    ListViewIcon,
    Search01Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import {
    NewBadge,
    Pagination,
    Shell,
    TransferTabs,
    type Paginated,
} from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import { bytes, date, dateTime, relativeDay } from '@/lib/format';
import type { ReceivedTransfer } from '@/lib/types';
import { cn } from '@/lib/utils';
import { create as newDrop, index as drops } from '@/routes/drops';
import { index, show } from '@/routes/received';

const dayMonth = (value: string) =>
    new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(
        new Date(value),
    );
const daysUntil = (value: string) =>
    Math.round(
        (new Date(value).setHours(0, 0, 0, 0) -
            new Date().setHours(0, 0, 0, 0)) /
            86_400_000,
    );

/** "Today, 11:02", "Yesterday", "11 Oct" (with the year once it is not this year's). */
function receivedWhen(value: string | null): string {
    if (!value) return '—';
    const day = relativeDay(value);
    if (day === 'today') {
        return `Today, ${new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))}`;
    }
    if (day === 'yesterday') return 'Yesterday';
    return new Date(value).getFullYear() === new Date().getFullYear()
        ? dayMonth(value)
        : date(value);
}

function ReceivedKept({
    transfer,
    compact = false,
    className,
}: {
    transfer: ReceivedTransfer;
    compact?: boolean;
    className?: string;
}) {
    const soon =
        transfer.available &&
        transfer.expires_at !== null &&
        daysUntil(transfer.expires_at) <= 3;
    const when = (value: string | null) =>
        compact && value ? dayMonth(value) : date(value);
    return (
        <span
            className={cn(
                soon
                    ? 'inline-flex items-center gap-1.5 font-medium text-orange-700'
                    : 'text-muted-foreground',
                className,
            )}
            title={
                soon ? `Deleted ${dateTime(transfer.expires_at)}` : undefined
            }
        >
            {soon && (
                <HugeiconsIcon
                    icon={Clock01Icon}
                    size={15}
                    strokeWidth={2}
                    aria-hidden="true"
                />
            )}
            {!transfer.available
                ? `Files deleted ${when(transfer.gone_at)}`
                : soon
                  ? `Deleted ${relativeDay(transfer.expires_at)}`
                  : `Kept until ${when(transfer.expires_at)}`}
        </span>
    );
}

type Props = {
    received: Paginated<ReceivedTransfer>;
    search: string;
    totals: { total: number; new: number };
};
export default function Index({ received, search, totals }: Props) {
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

    // Re-sync the box after every visit, the way the effect in transfers/index.tsx does, but during render.
    const [synced, setSynced] = useState({ search, url });
    if (synced.search !== search || synced.url !== url) {
        setSynced({ search, url });
        setQuery(search);
    }

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

    function visitSearch(nextSearch: string, replace = false) {
        cancelPending();
        router.get(
            index.url(),
            { search: nextSearch.trim() },
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

    return (
        <Shell active="transfers">
            <Head title="Received · My transfers" />
            <main className="mx-auto w-full max-w-6xl px-5 py-7 md:p-10">
                <div className="mb-5 flex flex-col gap-1 md:flex-row md:flex-wrap md:items-baseline md:justify-between md:gap-5">
                    <h1>My transfers</h1>
                    {totals.total > 0 && (
                        <p className="text-muted-foreground">
                            {totals.total} received
                            {totals.new > 0 && ` · ${totals.new} new`}
                        </p>
                    )}
                </div>
                <TransferTabs current="received" newCount={totals.new} />
                {totals.total > 0 && (
                    <>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <form
                                role="search"
                                className="relative w-full md:w-90"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    visitSearch(query, true);
                                }}
                            >
                                <label
                                    htmlFor="received-search"
                                    className="sr-only"
                                >
                                    Search received files
                                </label>
                                <HugeiconsIcon
                                    icon={Search01Icon}
                                    size={18}
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-3.5 left-3 text-muted-foreground"
                                />
                                <input
                                    id="received-search"
                                    type="search"
                                    placeholder="Search by sender or Drop"
                                    autoComplete="off"
                                    maxLength={255}
                                    className="pl-10"
                                    value={query}
                                    onChange={(event) => {
                                        const value = event.target.value;
                                        setQuery(value);
                                        cancelPending();
                                        searchTimer.current = setTimeout(
                                            () => visitSearch(value, true),
                                            300,
                                        );
                                    }}
                                />
                            </form>
                            <Link
                                href={drops()}
                                className="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold"
                            >
                                Manage Drops
                                <HugeiconsIcon
                                    icon={ArrowRight01Icon}
                                    size={16}
                                    aria-hidden="true"
                                />
                            </Link>
                        </div>
                        <p className="mb-4 text-sm text-muted-foreground">
                            Files people sent through your Drops. Only you can
                            see them.
                        </p>
                    </>
                )}
                <div aria-busy={pending}>
                    <p role="status" className="sr-only">
                        {pending
                            ? 'Updating received files'
                            : `${received.meta.total} ${received.meta.total === 1 ? 'match' : 'matches'}`}
                    </p>
                    {!received.data.length ? (
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
                                    ? 'Nothing received yet'
                                    : 'Nothing matches'}
                            </h2>
                            <p className="max-w-sm text-base text-muted-foreground">
                                {totals.total === 0
                                    ? 'Create a Drop and share its link. What people send arrives here.'
                                    : 'Try a sender’s name or a Drop title.'}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center justify-center gap-3">
                                {totals.total === 0 ? (
                                    <Link
                                        href={newDrop()}
                                        className={cn(buttonVariants())}
                                    >
                                        <HugeiconsIcon
                                            icon={Add01Icon}
                                            size={16}
                                            aria-hidden="true"
                                        />
                                        New Drop
                                    </Link>
                                ) : (
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setQuery('');
                                            visitSearch('');
                                        }}
                                    >
                                        Clear search
                                    </Button>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col border-t">
                            {received.data.map((transfer) => {
                                const sender =
                                    transfer.sender_name ?? 'Someone';
                                const drop =
                                    transfer.drop?.title ?? transfer.title;
                                return (
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
                                        <Avatar
                                            name={sender}
                                            url={null}
                                            className={cn(
                                                'size-9 border-0 bg-muted font-semibold',
                                                transfer.available
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                        />
                                        <div className="grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 md:grid-cols-6 md:gap-3 lg:grid-cols-8">
                                            <div className="col-span-2 flex min-w-0 flex-col gap-1 md:col-span-3 md:block">
                                                <h2 className="flex min-w-0 items-center gap-2 font-sans text-sm leading-snug tracking-normal">
                                                    <span className="truncate">
                                                        {sender}
                                                    </span>
                                                    {transfer.is_new && (
                                                        <NewBadge />
                                                    )}
                                                </h2>
                                                <p className="text-muted-foreground">
                                                    {transfer.files_count}{' '}
                                                    {transfer.files_count === 1
                                                        ? 'file'
                                                        : 'files'}{' '}
                                                    ·{' '}
                                                    {bytes(transfer.total_size)}
                                                    <span className="md:hidden">
                                                        {' · '}
                                                        <ReceivedKept
                                                            transfer={transfer}
                                                            compact
                                                        />
                                                    </span>
                                                </p>
                                            </div>
                                            <span className="flex min-w-0 text-xs md:col-span-2">
                                                <span
                                                    className="inline-flex max-w-full items-center gap-1.5 rounded-full border bg-muted px-2.5 py-1 font-semibold text-slate-700"
                                                    title={`Drop: ${drop}`}
                                                >
                                                    <HugeiconsIcon
                                                        icon={
                                                            PackageReceiveIcon
                                                        }
                                                        size={13}
                                                        aria-hidden="true"
                                                        className="shrink-0"
                                                    />
                                                    <span className="truncate">
                                                        {drop}
                                                    </span>
                                                </span>
                                            </span>
                                            <span className="text-right text-sm text-muted-foreground tabular-nums md:text-left">
                                                {receivedWhen(
                                                    transfer.received_at,
                                                )}
                                            </span>
                                            <ReceivedKept
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
                                );
                            })}
                        </div>
                    )}
                    {received.data.length > 0 && (
                        <Pagination page={received} navigation={navigation} />
                    )}
                </div>
            </main>
        </Shell>
    );
}
