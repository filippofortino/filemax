import {
    ArrowLeft01Icon,
    Calendar01Icon,
    Delete02Icon,
    Globe02Icon,
    UserGroupIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DownloadAll, FileDownload } from '@/components/downloads';
import {
    CopyLink,
    ErrorMessage,
    FileRow,
    PasswordBadge,
    Shell,
} from '@/components/filemax';
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
import { toast } from '@/components/ui/toast';
import { bytes, date, dateTime, relativeDay } from '@/lib/format';
import type { Transfer } from '@/lib/types';
import { cn } from '@/lib/utils';
import { destroy, extend, index, update } from '@/routes/transfers';
export default function Show({
    transfer,
    membersCount,
}: {
    transfer: Transfer;
    membersCount: number;
}) {
    const [allFiles, setAllFiles] = useState(false);
    const [sharingOpen, setSharingOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const sharing = useForm({ visibility: 'public' });
    const refresh = () => router.reload({ only: ['transfer'] });
    return (
        <Shell active="transfers">
            <Head title={transfer.title} />
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
                        <h1 className="wrap-anywhere">{transfer.title}</h1>
                        <span
                            className="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-primary/10 px-3 text-sm font-semibold text-primary"
                            title={
                                transfer.visibility === 'public'
                                    ? transfer.password_protected
                                        ? 'Anyone with the link and password. No account needed.'
                                        : 'Anyone with the link. No account needed.'
                                    : undefined
                            }
                        >
                            <HugeiconsIcon
                                icon={
                                    transfer.visibility === 'members'
                                        ? UserGroupIcon
                                        : Globe02Icon
                                }
                                size={13}
                                aria-hidden="true"
                            />
                            {transfer.visibility === 'members'
                                ? 'Members only'
                                : 'Public link'}
                        </span>
                        {transfer.password_protected && <PasswordBadge />}
                        {!transfer.available && (
                            <span className="inline-flex items-center gap-1 rounded-full border bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">
                                {transfer.revoked_at ? 'Deleted' : 'Expired'}
                            </span>
                        )}
                    </div>
                    <p className="text-muted-foreground">
                        Created {date(transfer.created_at)} ·{' '}
                        {transfer.available || transfer.revoked_at
                            ? 'Expires'
                            : 'Expired'}{' '}
                        {dateTime(transfer.expires_at)} · {transfer.files_count}{' '}
                        {transfer.files_count === 1 ? 'file' : 'files'} ·{' '}
                        {bytes(transfer.total_size)}
                    </p>
                </div>
                <Form
                    action={extend(transfer.id)}
                    className="mb-6 flex flex-col gap-2 empty:hidden"
                    options={{ preserveScroll: true }}
                    onSuccess={(page) =>
                        toast.add({
                            title: 'Transfer extended',
                            description: `Now expires ${dateTime((page.props.transfer as Transfer).expires_at)}.`,
                        })
                    }
                >
                    {({ errors, processing }) => (
                        <>
                            {transfer.can_extend && (
                                <div className="flex flex-col gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 md:flex-row md:items-center md:justify-between md:gap-5">
                                    <input
                                        type="hidden"
                                        name="expected_expires_at"
                                        value={transfer.expires_at ?? ''}
                                    />
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <p className="font-semibold">
                                            {transfer.available
                                                ? `Expires ${relativeDay(transfer.expires_at)}`
                                                : 'This transfer has expired'}
                                        </p>
                                        <p className="text-slate-700">
                                            {transfer.available
                                                ? 'After that, recipients can no longer download. Extending keeps the same link.'
                                                : `The link no longer works. Your files are kept until ${date(transfer.retained_until)}.`}
                                        </p>
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        aria-busy={processing}
                                    >
                                        <HugeiconsIcon
                                            icon={Calendar01Icon}
                                            size={18}
                                            aria-hidden="true"
                                        />
                                        {transfer.available
                                            ? 'Extend by 7 days'
                                            : 'Reactivate for 7 days'}
                                    </Button>
                                </div>
                            )}
                            <ErrorMessage>
                                {Object.values(errors).join(' ')}
                            </ErrorMessage>
                        </>
                    )}
                </Form>
                <div className="grid grid-cols-1 items-start gap-7 md:grid-cols-3 md:gap-10">
                    <section className="flex min-w-0 flex-col gap-7 md:col-span-2">
                        {transfer.url && (
                            <div className="flex flex-col gap-2.5">
                                <CopyLink url={transfer.url} />
                                {transfer.password_protected && (
                                    <p className="text-sm text-muted-foreground">
                                        Recipients need the password you set. It
                                        can’t be changed, removed or recovered.
                                        Filemax doesn’t keep a readable copy, so
                                        it can’t show it again.
                                    </p>
                                )}
                            </div>
                        )}
                        {transfer.visibility === 'members' && (
                            <section className="flex flex-col gap-2">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="font-sans text-sm font-semibold tracking-normal">
                                        Shared with
                                    </h2>
                                    {!transfer.revoked_at && (
                                        <Dialog
                                            open={sharingOpen}
                                            onOpenChange={setSharingOpen}
                                        >
                                            <DialogTrigger
                                                render={
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                    />
                                                }
                                            >
                                                Make public
                                            </DialogTrigger>
                                            <DialogContent>
                                                <DialogHeader>
                                                    <DialogTitle>
                                                        Make this transfer
                                                        public?
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        Anyone with the link
                                                        will be able to
                                                        download, without
                                                        signing in. The link and
                                                        expiry stay the same.
                                                        You can’t make it
                                                        members-only again.
                                                    </DialogDescription>
                                                </DialogHeader>
                                                <ErrorMessage>
                                                    {Object.values(
                                                        sharing.errors,
                                                    ).join(' ')}
                                                </ErrorMessage>
                                                <DialogFooter>
                                                    <Button
                                                        variant="outline"
                                                        type="button"
                                                        onClick={() =>
                                                            setSharingOpen(
                                                                false,
                                                            )
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                    <Button
                                                        disabled={
                                                            sharing.processing
                                                        }
                                                        onClick={() =>
                                                            sharing.patch(
                                                                update.url(
                                                                    transfer.id,
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                    onSuccess:
                                                                        () =>
                                                                            setSharingOpen(
                                                                                false,
                                                                            ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Make public
                                                    </Button>
                                                </DialogFooter>
                                            </DialogContent>
                                        </Dialog>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <span className="inline-flex flex-wrap items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-2 text-sm">
                                        <strong className="wrap-anywhere">
                                            Members of {transfer.organization}
                                        </strong>
                                        <span className="text-xs text-muted-foreground">
                                            {membersCount}{' '}
                                            {membersCount === 1
                                                ? 'member'
                                                : 'members'}
                                        </span>
                                    </span>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Members sign in to download. The link alone
                                    is not enough.
                                </p>
                            </section>
                        )}
                        <section className="flex flex-col gap-2">
                            <h2 className="font-sans text-sm font-semibold tracking-normal">
                                Message
                            </h2>
                            {transfer.message ? (
                                <p className="rounded-lg bg-muted px-4 py-3 text-base wrap-anywhere whitespace-pre-wrap">
                                    {transfer.message}
                                </p>
                            ) : (
                                <p className="text-muted-foreground italic">
                                    No message.
                                </p>
                            )}
                        </section>
                        <section>
                            <div className="mb-2 flex flex-wrap items-baseline justify-between gap-3">
                                <h2 className="font-sans text-sm font-semibold tracking-normal">
                                    Files
                                    {transfer.files.length > 4 && !allFiles && (
                                        <span className="font-normal text-muted-foreground">
                                            {' '}
                                            · showing 4 of{' '}
                                            {transfer.files.length}
                                        </span>
                                    )}
                                </h2>
                                <span className="text-sm text-muted-foreground">
                                    download clicks per file
                                </span>
                            </div>
                            <div className="border-t">
                                {transfer.files
                                    .slice(0, allFiles ? undefined : 4)
                                    .map((file) => (
                                        <FileRow
                                            key={file.id}
                                            name={file.original_name}
                                            size={file.size}
                                            variant="owner"
                                        >
                                            {transfer.available && (
                                                <FileDownload
                                                    token={transfer.token}
                                                    fileId={file.id}
                                                    name={file.original_name}
                                                    variant="owner"
                                                    onDownload={refresh}
                                                />
                                            )}
                                            <span
                                                className="min-w-3 text-right text-sm tabular-nums"
                                                title="Download-button actions"
                                            >
                                                {file.download_count || '—'}
                                            </span>
                                        </FileRow>
                                    ))}
                            </div>
                            {transfer.files.length > 4 && (
                                <Button
                                    className="mt-3 h-auto min-h-0 p-0 text-sm"
                                    variant="link"
                                    onClick={() => setAllFiles(!allFiles)}
                                >
                                    {allFiles
                                        ? 'Show fewer files'
                                        : `Show all ${transfer.files.length} files`}
                                </Button>
                            )}
                        </section>
                    </section>
                    <aside className="flex min-w-0 flex-col gap-4">
                        <div className="flex flex-col gap-3.5 rounded-lg border p-5">
                            <h2
                                className="font-sans text-sm font-semibold tracking-normal text-muted-foreground"
                                title="Counts download-button clicks, including your own."
                            >
                                Downloads
                            </h2>
                            <div className="flex items-baseline gap-2">
                                <strong
                                    className={cn(
                                        'font-heading text-4xl leading-none font-bold tracking-tight',
                                        transfer.download_count === 0 &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {transfer.download_count}
                                </strong>
                                {transfer.download_count > 0 && (
                                    <span className="text-sm text-muted-foreground">
                                        total
                                    </span>
                                )}
                            </div>
                            <div className="flex flex-col gap-1.5 border-t pt-2.5 text-sm text-muted-foreground">
                                <p>
                                    {transfer.first_opened_at
                                        ? `First opened ${date(transfer.first_opened_at)}`
                                        : 'Not opened yet.'}
                                </p>
                                {transfer.last_downloaded_at ? (
                                    <p>
                                        Last downloaded{' '}
                                        {date(transfer.last_downloaded_at)}
                                    </p>
                                ) : (
                                    <p>
                                        {transfer.first_opened_at
                                            ? 'Opened, but no downloads yet.'
                                            : 'No download clicks yet.'}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="flex flex-col gap-2">
                            {transfer.available && (
                                <DownloadAll
                                    token={transfer.token}
                                    totalSize={transfer.total_size}
                                    singleFileId={
                                        transfer.files.length === 1
                                            ? transfer.files[0].id
                                            : undefined
                                    }
                                    variant="outline"
                                    size="default"
                                    onDownload={refresh}
                                />
                            )}
                            {!transfer.revoked_at && (
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
                                        Delete transfer
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Delete this transfer?
                                            </DialogTitle>
                                            <DialogDescription>
                                                The Filemax link will stop
                                                working immediately and the
                                                files will be deleted. Your
                                                transfer history will remain.
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Form
                                            action={destroy(transfer.id)}
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
                                                            Keep transfer
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Delete transfer
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            )}
                            <p className="px-1 pt-1 text-center text-xs text-muted-foreground">
                                {transfer.revoked_at
                                    ? 'This link is no longer available.'
                                    : 'Deleting stops new access immediately. Downloads already started may finish.'}
                            </p>
                        </div>
                    </aside>
                </div>
            </main>
        </Shell>
    );
}
