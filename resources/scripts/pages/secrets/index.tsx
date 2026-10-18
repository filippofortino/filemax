import {
    Add01Icon,
    ArrowRight01Icon,
    Clock01Icon,
    EncryptIcon,
    Search01Icon,
    ViewIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Pagination, Shell } from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { date, dateTime } from '@/lib/format';
import type { Secret } from '@/lib/types';
import { cn } from '@/lib/utils';
import { create, index, show } from '@/routes/secrets';

type Status = 'all' | 'active' | 'soon' | 'expired';
const statuses: { value: Status; label: string; dot?: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'active', label: 'Active', dot: 'bg-emerald-600' },
    { value: 'soon', label: 'Expiring soon', dot: 'bg-amber-600' },
    { value: 'expired', label: 'Expired', dot: 'bg-slate-400' },
];

function SecretExpiry({
    secret,
    compact = false,
    className,
}: {
    secret: Secret;
    compact?: boolean;
    className?: string;
}) {
    const when = (value: string) =>
        compact
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
              }).format(new Date(value))
            : dateTime(value);
    return (
        <span
            className={cn(
                secret.expiring_soon
                    ? 'inline-flex items-center gap-1.5 font-medium text-orange-700'
                    : 'text-muted-foreground',
                className,
            )}
            title={
                secret.available
                    ? `Expires ${dateTime(secret.expires_at)}`
                    : undefined
            }
        >
            {secret.expiring_soon && (
                <HugeiconsIcon
                    icon={Clock01Icon}
                    size={15}
                    strokeWidth={2}
                    aria-hidden="true"
                />
            )}
            {secret.reason === 'opened'
                ? `Deleted after ${secret.max_views === 1 ? 'its view' : `${secret.max_views} views`}`
                : secret.reason === 'deleted'
                  ? 'Deleted'
                  : secret.reason === 'expired'
                    ? `Expired ${when(secret.expires_at)}`
                    : secret.expiring_soon
                      ? `Expires in ${secret.expires_in}`
                      : secret.max_views === 1
                        ? `Opens once · until ${compact ? when(secret.expires_at) : date(secret.expires_at)}`
                        : `Expires ${when(secret.expires_at)}`}
        </span>
    );
}

type Props = {
    secrets: {
        data: Secret[];
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
    search: string;
    status: Status;
    statusCounts: Record<Status, number>;
    totals: {
        total: number;
        active: number;
    };
};
export default function Index({
    secrets,
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
        replace = false,
    ) {
        cancelPending();
        router.get(
            index.url(),
            {
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

    return (
        <Shell active="secrets">
            <Head title="Secrets" />
            <main className="mx-auto w-full max-w-6xl px-5 py-7 md:p-10">
                <div className="mb-2 flex flex-col gap-1 md:flex-row md:flex-wrap md:items-baseline md:justify-between md:gap-5">
                    <h1>Secrets</h1>
                    {totals.total > 0 && (
                        <p className="text-muted-foreground">
                            {totals.total}{' '}
                            {totals.total === 1 ? 'secret' : 'secrets'} ·{' '}
                            {totals.active} active
                        </p>
                    )}
                </div>
                <p className="mb-6 max-w-2xl text-base text-muted-foreground">
                    Passwords and notes, encrypted in your browser before
                    they’re sent. Filemax never has the key, so here you see
                    only whether each one was opened.
                </p>
                {totals.total > 0 && (
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <form
                            role="search"
                            className="relative w-full md:w-90"
                            onSubmit={(event) => {
                                event.preventDefault();
                                visitFilters(query, status, true);
                            }}
                        >
                            <label htmlFor="secret-search" className="sr-only">
                                Search secrets
                            </label>
                            <HugeiconsIcon
                                icon={Search01Icon}
                                size={18}
                                aria-hidden="true"
                                className="pointer-events-none absolute top-3.5 left-3 text-muted-foreground"
                            />
                            <input
                                id="secret-search"
                                type="search"
                                placeholder="Search secrets by label"
                                autoComplete="off"
                                maxLength={255}
                                className="pl-10"
                                value={query}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    setQuery(value);
                                    cancelPending();
                                    searchTimer.current = setTimeout(
                                        () => visitFilters(value, status, true),
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
                )}
                <div aria-busy={pending}>
                    <p role="status" className="sr-only">
                        {pending
                            ? 'Updating secrets'
                            : `${secrets.meta.total} matching secrets`}
                    </p>
                    {!secrets.data.length ? (
                        <div className="flex min-h-80 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-input px-6 py-16 text-center">
                            <span className="flex size-14 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <HugeiconsIcon
                                    icon={EncryptIcon}
                                    size={24}
                                    aria-hidden="true"
                                />
                            </span>
                            <h2>
                                {totals.total === 0
                                    ? 'No secrets yet'
                                    : 'No secrets here'}
                            </h2>
                            <p className="max-w-sm text-base text-muted-foreground">
                                {totals.total === 0
                                    ? 'Share a password or a note that only the person with the link can read.'
                                    : 'Try a different search or filter, or create a new secret.'}
                            </p>
                            <div className="mt-2 flex flex-wrap items-center justify-center gap-3">
                                {totals.total > 0 && (
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            setQuery('');
                                            visitFilters('', 'all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                )}
                                <Link
                                    href={create()}
                                    aria-label="Create a secret"
                                    className={cn(buttonVariants())}
                                >
                                    <HugeiconsIcon
                                        icon={Add01Icon}
                                        size={16}
                                        aria-hidden="true"
                                    />
                                    New secret
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col border-t">
                            {secrets.data.map((secret) => (
                                <Link
                                    className={cn(
                                        'flex min-h-16 items-start gap-3 border-b py-3 hover:bg-muted/50 hover:text-foreground md:items-center md:p-3',
                                        secret.available
                                            ? 'text-foreground'
                                            : 'text-muted-foreground',
                                    )}
                                    key={secret.id}
                                    href={show(secret.id)}
                                >
                                    <span
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                            secret.available
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <HugeiconsIcon
                                            icon={EncryptIcon}
                                            size={20}
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div className="grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 md:grid-cols-6 md:gap-3 lg:grid-cols-8">
                                        <div className="col-span-2 flex min-w-0 flex-col gap-1 md:col-span-3 md:block lg:col-span-4">
                                            <h2
                                                className={cn(
                                                    'truncate font-sans text-sm leading-snug tracking-normal',
                                                    !secret.labelled &&
                                                        'text-muted-foreground',
                                                )}
                                            >
                                                {secret.title}
                                            </h2>
                                            <p className="text-muted-foreground">
                                                <span className="hidden md:inline">
                                                    Created{' '}
                                                    {date(secret.created_at)}
                                                </span>
                                                <SecretExpiry
                                                    secret={secret}
                                                    compact
                                                    className="md:hidden"
                                                />
                                            </p>
                                        </div>
                                        <span
                                            className="flex items-center gap-1.5 text-sm md:col-span-1 lg:col-span-2"
                                            title="Times the secret was opened"
                                        >
                                            {secret.view_count ? (
                                                <>
                                                    <HugeiconsIcon
                                                        icon={ViewIcon}
                                                        size={16}
                                                        aria-hidden="true"
                                                        className="shrink-0"
                                                    />
                                                    {secret.max_views === 1
                                                        ? `Opened ${date(secret.last_opened_at)}`
                                                        : `${secret.view_count} of ${secret.max_views} views`}
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    Not opened
                                                </span>
                                            )}
                                        </span>
                                        <SecretExpiry
                                            secret={secret}
                                            className="hidden text-sm md:col-span-2 md:inline-flex"
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
                    {secrets.data.length > 0 && (
                        <Pagination page={secrets} navigation={navigation} />
                    )}
                </div>
            </main>
        </Shell>
    );
}
