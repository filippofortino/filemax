import {
    Add01Icon,
    Clock01Icon,
    Copy01Icon,
    PackageReceiveIcon,
    Tick02Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    ErrorMessage,
    Pagination,
    Shell,
    type Paginated,
} from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { toast } from '@/components/ui/toast';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { rulesSummary } from '@/lib/drop';
import { date, dateTime, relativeDay } from '@/lib/format';
import type { Drop } from '@/lib/types';
import { cn } from '@/lib/utils';
import { close, create, index } from '@/routes/drops';
import { index as received } from '@/routes/received';

type Status = 'all' | 'open' | 'closed';
const statuses: { value: Status; label: string; dot?: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'open', label: 'Open', dot: 'bg-emerald-600' },
    { value: 'closed', label: 'Closed', dot: 'bg-slate-400' },
];

function deadline(drop: Drop): {
    label: string;
    title?: string;
    soon: boolean;
} {
    if (drop.open) {
        const midnight = (value: string | number) =>
            new Date(value).setHours(0, 0, 0, 0);
        const daysLeft = Math.round(
            (midnight(drop.closes_at) - midnight(Date.now())) / 86_400_000,
        );
        return daysLeft <= 3
            ? {
                  label: `Closes ${relativeDay(drop.closes_at)}`,
                  title: `Closes ${dateTime(drop.closes_at)}`,
                  soon: true,
              }
            : { label: `Open until ${dateTime(drop.closes_at)}`, soon: false };
    }
    if (!drop.closed_early) {
        return {
            label: `Closed ${date(drop.closed_at)}`,
            title: 'Reached its deadline',
            soon: false,
        };
    }
    return {
        label:
            relativeDay(drop.closed_at) === 'today'
                ? `Closed by you today, ${new Intl.DateTimeFormat('en-GB', {
                      hour: '2-digit',
                      minute: '2-digit',
                  }).format(new Date(drop.closed_at))}`
                : `Closed by you ${date(drop.closed_at)}`,
        soon: false,
    };
}

function DropRow({ drop }: { drop: Drop }) {
    const [copied, setCopied] = useState(false);
    const copyTimer = useRef<ReturnType<typeof setTimeout> | undefined>(
        undefined,
    );
    useEffect(() => () => clearTimeout(copyTimer.current), []);
    const due = deadline(drop);

    async function copy() {
        try {
            await navigator.clipboard.writeText(drop.url);
        } catch {
            toast.add({
                type: 'error',
                title: 'Link not copied',
                description: `Copy is unavailable. Copy the link manually: ${drop.url}`,
                timeout: 0,
            });
            return;
        }
        setCopied(true);
        clearTimeout(copyTimer.current);
        copyTimer.current = setTimeout(() => setCopied(false), 2000);
    }

    return (
        <div
            className={cn(
                'flex min-h-16 flex-wrap items-start gap-3 border-b py-3 md:flex-nowrap md:items-center md:p-3',
                drop.open ? 'text-foreground' : 'text-muted-foreground',
            )}
        >
            <span
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                    drop.open
                        ? 'bg-primary/10 text-primary'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                <HugeiconsIcon
                    icon={PackageReceiveIcon}
                    size={20}
                    aria-hidden="true"
                />
            </span>
            <div className="grid min-w-0 flex-1 grid-cols-1 items-center gap-2 md:grid-cols-6 md:gap-3 lg:grid-cols-8">
                <div className="min-w-0 md:col-span-3">
                    <h2
                        id={`drop-${drop.id}`}
                        tabIndex={-1}
                        className="truncate font-sans text-sm leading-snug tracking-normal"
                    >
                        {drop.title}
                    </h2>
                    <p className="truncate text-muted-foreground">
                        {rulesSummary(drop)}
                    </p>
                </div>
                <span className="text-sm md:col-span-2">
                    {drop.uploads_count > 0 ? (
                        <Link
                            href={received({ query: { search: drop.title } })}
                            className="font-semibold"
                        >
                            {drop.uploads_count}{' '}
                            {drop.uploads_count === 1 ? 'upload' : 'uploads'}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">
                            No uploads yet
                        </span>
                    )}
                </span>
                <span
                    className={cn(
                        'text-sm md:col-span-6 lg:col-span-2',
                        due.soon
                            ? 'inline-flex items-center gap-1.5 font-medium text-orange-700'
                            : 'text-muted-foreground',
                    )}
                    title={due.title}
                >
                    {due.soon && (
                        <HugeiconsIcon
                            icon={Clock01Icon}
                            size={15}
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                    )}
                    {due.label}
                </span>
            </div>
            {/* Closed rows keep the empty column on wider screens so the rows stay aligned. */}
            <div className="flex w-full shrink-0 flex-wrap items-center gap-2 pl-12 max-md:empty:hidden md:w-58 md:justify-end md:pl-0">
                {drop.open && (
                    <>
                        <Button
                            variant="outline"
                            aria-label={`Copy link to ${drop.title}`}
                            onClick={copy}
                        >
                            <HugeiconsIcon
                                icon={copied ? Tick02Icon : Copy01Icon}
                                size={16}
                                aria-hidden="true"
                            />
                            {copied ? 'Copied' : 'Copy link'}
                        </Button>
                        <span role="status" className="sr-only">
                            {copied ? 'Link copied to clipboard' : ''}
                        </span>
                        <Dialog>
                            <DialogTrigger
                                render={
                                    <Button
                                        variant="outline"
                                        aria-label={`Close ${drop.title}`}
                                    />
                                }
                            >
                                Close
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Close this Drop?</DialogTitle>
                                    <DialogDescription>
                                        “{drop.title}” stops accepting files
                                        now. Uploads already started may finish.
                                        Everything you’ve received stays in
                                        Received.
                                    </DialogDescription>
                                </DialogHeader>
                                <Form
                                    action={close(drop.id)}
                                    options={{
                                        preserveScroll: true,
                                        preserveState: true,
                                    }}
                                    className="flex flex-col gap-4"
                                    // The Close button goes away with the dialog, so focus the row, or the heading if it left this filter.
                                    onSuccess={() =>
                                        requestAnimationFrame(() =>
                                            (
                                                document.getElementById(
                                                    `drop-${drop.id}`,
                                                ) ??
                                                document.getElementById(
                                                    'drops-heading',
                                                )
                                            )?.focus(),
                                        )
                                    }
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <ErrorMessage>
                                                {Object.values(errors).join(
                                                    ' ',
                                                )}
                                            </ErrorMessage>
                                            <DialogFooter>
                                                <DialogClose
                                                    render={
                                                        <Button variant="outline" />
                                                    }
                                                >
                                                    Keep open
                                                </DialogClose>
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    disabled={processing}
                                                >
                                                    Close Drop
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </>
                )}
            </div>
        </div>
    );
}

type Props = {
    drops: Paginated<Drop>;
    status: Status;
    statusCounts: Record<Status, number>;
};
export default function Index({ drops, status, statusCounts }: Props) {
    const [pending, setPending] = useState(false);
    const cancelVisit = useRef<(() => void) | undefined>(undefined);
    const cancelPending = useCallback(() => {
        cancelVisit.current?.();
        cancelVisit.current = undefined;
    }, []);

    useEffect(() => {
        window.addEventListener('popstate', cancelPending);
        return () => {
            window.removeEventListener('popstate', cancelPending);
            cancelPending();
        };
    }, [cancelPending]);

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
        <Shell active="drops">
            <Head title="Drops" />
            <main className="mx-auto w-full max-w-6xl px-5 py-7 md:p-10">
                <div className="mb-2 flex flex-col gap-1 md:flex-row md:flex-wrap md:items-baseline md:justify-between md:gap-5">
                    <h1 id="drops-heading" tabIndex={-1}>
                        Drops
                    </h1>
                    {statusCounts.all > 0 && (
                        <p className="text-muted-foreground">
                            {statusCounts.all}{' '}
                            {statusCounts.all === 1 ? 'Drop' : 'Drops'} ·{' '}
                            {statusCounts.open} open
                        </p>
                    )}
                </div>
                <p className="mb-6 max-w-2xl text-base text-muted-foreground">
                    Links that let people send you files, no account needed.
                    <br />
                    What they send arrives in{' '}
                    <Link href={received()} className="font-semibold">
                        My transfers › Received
                    </Link>
                    .
                </p>
                {statusCounts.all > 0 && (
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <ToggleGroup
                            aria-label="Filter by status"
                            value={[status]}
                            variant="status"
                            size="lg"
                            spacing={0.5}
                            className="w-full rounded-lg bg-muted p-1 md:w-fit"
                            onValueChange={(values) => {
                                if (values[0] || pending) {
                                    router.get(
                                        index.url(),
                                        { status: values[0] ?? status },
                                        navigation,
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
                                    {option.label}
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
                            ? 'Updating Drops'
                            : `${drops.meta.total} matching ${drops.meta.total === 1 ? 'Drop' : 'Drops'}`}
                    </p>
                    {!drops.data.length ? (
                        <div className="flex min-h-80 flex-col items-center justify-center gap-4 rounded-xl border border-dashed border-input px-6 py-16 text-center">
                            <span className="flex size-14 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                <HugeiconsIcon
                                    icon={PackageReceiveIcon}
                                    size={24}
                                    aria-hidden="true"
                                />
                            </span>
                            <h2>No Drops here</h2>
                            <p className="max-w-sm text-base text-muted-foreground">
                                Create a Drop whenever you need files from
                                someone. They won’t need an account.
                            </p>
                            <Link
                                href={create()}
                                className={cn(buttonVariants(), 'mt-2')}
                            >
                                <HugeiconsIcon
                                    icon={Add01Icon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                New Drop
                            </Link>
                        </div>
                    ) : (
                        <div className="flex flex-col border-t">
                            {drops.data.map((drop) => (
                                <DropRow key={drop.id} drop={drop} />
                            ))}
                        </div>
                    )}
                    {drops.data.length > 0 && (
                        <Pagination page={drops} navigation={navigation} />
                    )}
                </div>
            </main>
        </Shell>
    );
}
