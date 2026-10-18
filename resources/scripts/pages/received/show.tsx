import {
    ArrowLeft01Icon,
    Delete02Icon,
    PackageReceiveIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar } from '@/components/avatar';
import { DownloadAll, FileDownload } from '@/components/downloads';
import { ErrorMessage, FileRow, Shell } from '@/components/filemax';
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
import { bytes, dateTime } from '@/lib/format';
import type { ReceivedTransfer } from '@/lib/types';
import { index as drops } from '@/routes/drops';
import { destroy, index } from '@/routes/received';

const dayMonth = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-GB', {
              day: 'numeric',
              month: 'short',
          }).format(new Date(value))
        : '—';
const yearTime = (value: string | null) =>
    value
        ? new Intl.DateTimeFormat('en-GB', {
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          }).format(new Date(value))
        : '';

export default function Show({ transfer }: { transfer: ReceivedTransfer }) {
    const [allFiles, setAllFiles] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const sender = transfer.sender_name ?? 'Someone';
    const filesLabel = `${transfer.files_count} ${transfer.files_count === 1 ? 'file' : 'files'}`;
    const token = transfer.available ? transfer.token : null;
    const keptUntil = transfer.available
        ? transfer.expires_at
        : transfer.gone_at;
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
                    Received
                </Link>
                <div className="mb-6 flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="wrap-anywhere">{transfer.title}</h1>
                        <span className="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-primary/10 px-3 text-sm font-semibold text-primary">
                            <HugeiconsIcon
                                icon={PackageReceiveIcon}
                                size={13}
                                aria-hidden="true"
                            />
                            Received
                        </span>
                    </div>
                    <p className="text-muted-foreground">
                        From {sender} · received{' '}
                        {dateTime(transfer.received_at)} · {filesLabel} ·{' '}
                        {bytes(transfer.total_size)}
                    </p>
                </div>
                <div className="grid grid-cols-1 items-start gap-7 md:grid-cols-3 md:gap-10">
                    <section className="flex min-w-0 flex-col gap-7 md:col-span-2">
                        <section className="flex flex-col gap-2">
                            <h2 className="font-sans text-sm font-semibold tracking-normal">
                                From
                            </h2>
                            <div className="flex items-center gap-3">
                                <Avatar
                                    name={sender}
                                    url={null}
                                    className="size-9 border-0 bg-muted text-sm font-semibold text-foreground"
                                />
                                <div className="flex min-w-0 flex-col">
                                    <strong className="wrap-anywhere">
                                        {sender}
                                    </strong>
                                    <span className="text-sm text-muted-foreground">
                                        The name they typed. Filemax doesn’t
                                        check it.
                                    </span>
                                </div>
                            </div>
                        </section>
                        {transfer.message && (
                            <section className="flex flex-col gap-2">
                                <h2 className="font-sans text-sm font-semibold tracking-normal">
                                    Message
                                </h2>
                                <p className="rounded-lg bg-muted px-4 py-3 text-base wrap-anywhere whitespace-pre-wrap">
                                    {transfer.message}
                                </p>
                            </section>
                        )}
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
                                    {filesLabel} · {bytes(transfer.total_size)}
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
                                            {token && (
                                                <FileDownload
                                                    token={token}
                                                    fileId={file.id}
                                                    name={file.original_name}
                                                    variant="owner"
                                                />
                                            )}
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
                            <h2 className="font-sans text-sm font-semibold tracking-normal text-muted-foreground">
                                {transfer.available
                                    ? 'Kept until'
                                    : 'Files deleted'}
                            </h2>
                            <div className="flex items-baseline gap-2">
                                <strong className="font-heading text-4xl leading-none font-bold tracking-tight">
                                    {dayMonth(keptUntil)}
                                </strong>
                                <span className="text-sm text-muted-foreground">
                                    {yearTime(keptUntil)}
                                </span>
                            </div>
                            {transfer.available && (
                                <div className="flex flex-col gap-1.5 border-t pt-2.5 text-sm text-muted-foreground">
                                    <p>
                                        {transfer.keep_days}{' '}
                                        {transfer.keep_days === 1
                                            ? 'day'
                                            : 'days'}{' '}
                                        after the upload, as the Drop was set.
                                    </p>
                                    <p>Closing the Drop doesn’t change this.</p>
                                </div>
                            )}
                        </div>
                        {transfer.drop && (
                            <div className="flex flex-col gap-1.5 rounded-lg border p-5">
                                <h2 className="font-sans text-sm font-semibold tracking-normal text-muted-foreground">
                                    Drop
                                </h2>
                                <Link
                                    href={drops()}
                                    className="font-semibold wrap-anywhere"
                                >
                                    {transfer.drop.title}
                                </Link>
                                <p className="text-sm text-muted-foreground">
                                    {transfer.drop.open
                                        ? `Open until ${dateTime(transfer.drop.closes_at)}. New uploads arrive as separate items.`
                                        : transfer.drop.closed_early
                                          ? `Closed by you ${dateTime(transfer.drop.closed_at)}. Nobody can add files to it.`
                                          : `Closed ${dateTime(transfer.drop.closed_at)}.`}
                                </p>
                            </div>
                        )}
                        {token && (
                            <div className="flex flex-col gap-2">
                                <DownloadAll
                                    token={token}
                                    totalSize={transfer.total_size}
                                    singleFileId={
                                        transfer.files.length === 1
                                            ? transfer.files[0].id
                                            : undefined
                                    }
                                    variant="outline"
                                    size="default"
                                />
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
                                        Delete files
                                    </DialogTrigger>
                                    <DialogContent>
                                        <DialogHeader>
                                            <DialogTitle>
                                                Delete these files?
                                            </DialogTitle>
                                            <DialogDescription>
                                                {sender}’s {filesLabel}{' '}
                                                {transfer.files_count === 1
                                                    ? 'is'
                                                    : 'are'}{' '}
                                                deleted now instead of on{' '}
                                                {dayMonth(transfer.expires_at)}.
                                                The Drop stays as it is.
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
                                                            Keep files
                                                        </Button>
                                                        <Button
                                                            type="submit"
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Delete files
                                                        </Button>
                                                    </DialogFooter>
                                                </>
                                            )}
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                                <p className="px-1 pt-1 text-center text-xs text-muted-foreground">
                                    Deleting removes the files now, not on{' '}
                                    {dayMonth(transfer.expires_at)}.
                                </p>
                            </div>
                        )}
                    </aside>
                </div>
            </main>
        </Shell>
    );
}
