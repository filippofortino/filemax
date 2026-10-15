import {
    Alert02Icon,
    ArrowLeft01Icon,
    Delete02Icon,
    LockKeyIcon,
    ViewIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorMessage, Shell } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { dateTime } from '@/lib/format';
import type { Secret } from '@/lib/types';
import { cn } from '@/lib/utils';
import { destroy } from '@/routes/secrets';
import { index } from '@/routes/transfers';

export default function ShowSecret({ secret }: { secret: Secret }) {
    const [deleteOpen, setDeleteOpen] = useState(false);
    const views = secret.view_count;
    const activity = [
        { label: 'Created', at: secret.created_at },
        secret.wrong_key_count > 0 && {
            label: `Wrong key entered${secret.wrong_key_count > 1 ? ` ×${secret.wrong_key_count}` : ''}`,
            at: secret.last_wrong_key_at,
            warning: true,
        },
        views > 0 && {
            label:
                secret.reason === 'opened'
                    ? views > 1
                        ? `Opened ${views} times, then deleted`
                        : 'Opened, then deleted'
                    : views > 1
                      ? `Opened ${views} times`
                      : 'Opened',
            at: secret.last_opened_at,
        },
        secret.reason === 'expired' && {
            label: 'Expired',
            at: secret.expires_at,
        },
        secret.reason === 'deleted' && {
            label: 'Deleted',
            at: secret.revoked_at,
        },
    ]
        .filter(
            (
                entry,
            ): entry is { label: string; at: string; warning?: boolean } =>
                Boolean(entry && entry.at),
        )
        .sort((a, b) => Date.parse(a.at) - Date.parse(b.at));

    return (
        <Shell active="transfers">
            <Head title={secret.title} />
            <main className="mx-auto w-full max-w-6xl px-5 py-7 md:px-10 md:py-8">
                <Link
                    href={index()}
                    className="mb-6 inline-flex items-center gap-1.5 text-sm font-semibold"
                >
                    <HugeiconsIcon
                        icon={ArrowLeft01Icon}
                        size={16}
                        aria-hidden="true"
                    />
                    My transfers
                </Link>
                <div className="mb-6 flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1
                            className={cn(
                                'wrap-anywhere',
                                !secret.labelled && 'text-muted-foreground',
                            )}
                        >
                            {secret.title}
                        </h1>
                        <span
                            className="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-muted px-3 text-sm font-semibold text-slate-700"
                            title="Only the link and its key can open it."
                        >
                            <HugeiconsIcon
                                icon={LockKeyIcon}
                                size={13}
                                aria-hidden="true"
                            />
                            Encrypted
                        </span>
                        {secret.available ? (
                            <span className="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-muted px-3 text-sm font-semibold text-slate-700">
                                <HugeiconsIcon
                                    icon={ViewIcon}
                                    size={13}
                                    aria-hidden="true"
                                />
                                {secret.max_views === 1
                                    ? 'Opens once'
                                    : `Opens up to ${secret.max_views} times`}
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1 rounded-full border bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">
                                {secret.reason === 'expired'
                                    ? 'Expired'
                                    : 'Deleted'}
                            </span>
                        )}
                    </div>
                    <p className="text-muted-foreground">
                        Secret · created {dateTime(secret.created_at)} ·{' '}
                        {secret.reason === 'opened'
                            ? `opened and deleted ${dateTime(secret.last_opened_at)}`
                            : secret.reason === 'deleted'
                              ? `deleted ${dateTime(secret.revoked_at)}`
                              : `${secret.reason === 'expired' ? 'expired' : 'expires'} ${dateTime(secret.expires_at)}`}
                    </p>
                </div>
                <div className="grid grid-cols-1 items-start gap-7 md:grid-cols-3 md:gap-10">
                    <section className="flex min-w-0 flex-col gap-7 md:col-span-2">
                        <div className="flex flex-col gap-2.5">
                            <p className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-slate-700">
                                Filemax never had the key, so it can’t show this
                                secret or its link here. Only the person you
                                sent them to can open it.
                            </p>
                            {secret.available && (
                                <p className="text-sm text-muted-foreground">
                                    Lost the link? Delete this secret and create
                                    a new one.
                                </p>
                            )}
                        </div>
                        <section className="flex flex-col gap-2">
                            <h2 className="font-sans text-sm font-semibold tracking-normal">
                                Activity
                            </h2>
                            <ol className="border-t">
                                {activity.map((entry) => (
                                    <li
                                        key={entry.label}
                                        className="flex min-h-12 items-center justify-between gap-3 border-b py-3"
                                    >
                                        <span
                                            className={cn(
                                                entry.warning &&
                                                    'inline-flex items-center gap-2 text-slate-700',
                                            )}
                                        >
                                            {entry.warning && (
                                                <HugeiconsIcon
                                                    icon={Alert02Icon}
                                                    size={16}
                                                    aria-hidden="true"
                                                />
                                            )}
                                            {entry.label}
                                        </span>
                                        <span className="text-sm text-muted-foreground tabular-nums">
                                            {dateTime(entry.at)}
                                        </span>
                                    </li>
                                ))}
                                {views === 0 && secret.available && (
                                    <li className="flex min-h-12 items-center justify-between gap-3 border-b py-3 text-muted-foreground">
                                        <span>Not opened yet</span>
                                        <span className="text-sm">—</span>
                                    </li>
                                )}
                            </ol>
                        </section>
                    </section>
                    <aside className="flex min-w-0 flex-col gap-4">
                        <div className="flex flex-col gap-3.5 rounded-lg border p-5">
                            <h2 className="font-sans text-sm font-semibold tracking-normal text-muted-foreground">
                                Views
                            </h2>
                            <div className="flex items-baseline gap-2">
                                <strong
                                    className={cn(
                                        'font-heading text-4xl leading-none font-bold tracking-tight',
                                        views === 0 && 'text-muted-foreground',
                                    )}
                                >
                                    {views}
                                </strong>
                                <span className="text-sm text-muted-foreground">
                                    of {secret.max_views}
                                </span>
                            </div>
                            <div className="flex flex-col gap-1.5 border-t pt-2.5 text-sm text-muted-foreground">
                                <p>
                                    {views === 0
                                        ? 'Not opened yet'
                                        : `${views === 1 ? 'Opened' : 'Last opened'} ${dateTime(secret.last_opened_at)}`}
                                </p>
                                <p>
                                    {secret.max_views === 1
                                        ? 'Deleted after its first view'
                                        : `Deleted after ${secret.max_views} views`}
                                </p>
                                {secret.wrong_key_count > 0 && (
                                    <p>
                                        {secret.wrong_key_count} wrong{' '}
                                        {secret.wrong_key_count === 1
                                            ? 'key'
                                            : 'keys'}{' '}
                                        entered
                                    </p>
                                )}
                            </div>
                        </div>
                        {secret.available ? (
                            <div className="flex flex-col gap-2">
                                <Dialog
                                    open={deleteOpen}
                                    onOpenChange={setDeleteOpen}
                                >
                                    <DialogTrigger
                                        render={
                                            <Button variant="destructive" />
                                        }
                                    >
                                        <HugeiconsIcon
                                            icon={Delete02Icon}
                                            size={18}
                                            aria-hidden="true"
                                        />
                                        Delete secret
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Delete this secret?
                                            </DialogTitle>
                                            <DialogDescription>
                                                The link stops working
                                                immediately, even for someone
                                                who has the key.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Form
                                            action={destroy(secret.id)}
                                            className="flex flex-col gap-4"
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <ErrorMessage>
                                                        {Object.values(
                                                            errors,
                                                        ).join(' ')}
                                                    </ErrorMessage>
                                                    <DialogFooter>
                                                        <Button
                                                            variant="outline"
                                                            onClick={() =>
                                                                setDeleteOpen(
                                                                    false,
                                                                )
                                                            }
                                                        >
                                                            Keep secret
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Delete secret
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                                <p className="px-1 pt-1 text-center text-xs text-muted-foreground">
                                    Deleting stops the link immediately.
                                </p>
                            </div>
                        ) : (
                            <p className="px-1 text-sm text-muted-foreground">
                                {secret.reason === 'opened'
                                    ? 'Nothing left to delete. Filemax deleted it when it was opened.'
                                    : secret.reason === 'expired'
                                      ? 'Nothing left to delete. Filemax deleted it when it expired.'
                                      : 'This link is no longer available.'}
                            </p>
                        )}
                    </aside>
                </div>
            </main>
        </Shell>
    );
}
