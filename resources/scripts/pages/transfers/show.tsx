import {
    ArrowLeft01Icon,
    Delete02Icon,
    Globe02Icon,
    UserGroupIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DownloadAll, FileDownload } from '@/components/downloads';
import {
    CopyLink,
    ErrorMessage,
    FileRow,
    Shell,
    TeamPicker,
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
import { bytes, date, dateTime } from '@/lib/format';
import type { Team, Transfer } from '@/lib/types';
import { cn } from '@/lib/utils';
import { destroy, index, update } from '@/routes/transfers';
export default function Show({
    transfer,
    teams,
}: {
    transfer: Transfer;
    teams: Team[];
}) {
    const [allFiles, setAllFiles] = useState(false);
    const [sharingOpen, setSharingOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const sharing = useForm({
        team_ids: transfer.teams.map((team) => team.id),
    });
    const deletion = useForm({});
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
                                    ? 'Anyone with the link. No account needed.'
                                    : undefined
                            }
                        >
                            <HugeiconsIcon
                                icon={
                                    transfer.visibility === 'teams'
                                        ? UserGroupIcon
                                        : Globe02Icon
                                }
                                size={13}
                                aria-hidden="true"
                            />
                            {transfer.visibility === 'teams'
                                ? `${transfer.teams.length} ${transfer.teams.length === 1 ? 'team' : 'teams'}`
                                : 'Public link'}
                        </span>
                        {!transfer.available && (
                            <span className="inline-flex items-center gap-1 rounded-full border bg-muted px-2 py-1 text-xs font-medium text-muted-foreground">
                                {transfer.revoked_at ? 'Deleted' : 'Expired'}
                            </span>
                        )}
                    </div>
                    <p className="text-muted-foreground">
                        Created {date(transfer.created_at)} · Expires{' '}
                        {dateTime(transfer.expires_at)} · {transfer.files_count}{' '}
                        {transfer.files_count === 1 ? 'file' : 'files'} ·{' '}
                        {bytes(transfer.total_size)}
                    </p>
                </div>
                <div className="grid grid-cols-1 items-start gap-7 md:grid-cols-3 md:gap-10">
                    <section className="flex min-w-0 flex-col gap-7 md:col-span-2">
                        {transfer.url && <CopyLink url={transfer.url} />}
                        {transfer.visibility === 'teams' && (
                            <section className="flex flex-col gap-2">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="font-sans text-sm font-semibold tracking-normal">
                                        Shared with
                                    </h2>
                                    {transfer.visibility === 'teams' &&
                                        !transfer.revoked_at && (
                                            <Dialog
                                                open={sharingOpen}
                                                onOpenChange={(open) => {
                                                    setSharingOpen(open);
                                                    if (open) {
                                                        sharing.setData(
                                                            'team_ids',
                                                            transfer.teams.map(
                                                                (team) =>
                                                                    team.id,
                                                            ),
                                                        );
                                                        sharing.clearErrors();
                                                    }
                                                }}
                                            >
                                                <DialogTrigger
                                                    render={
                                                        <Button
                                                            variant="link"
                                                            size="sm"
                                                        />
                                                    }
                                                    className="h-auto min-h-0 p-0 text-sm"
                                                >
                                                    Change teams
                                                </DialogTrigger>
                                                <DialogContent>
                                                    <DialogHeader>
                                                        <DialogTitle>
                                                            Change teams
                                                        </DialogTitle>
                                                        <DialogDescription>
                                                            Members of any
                                                            selected team can
                                                            download. The link
                                                            and expiry stay the
                                                            same.
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <form
                                                        className="flex flex-col gap-5"
                                                        onSubmit={(event) => {
                                                            event.preventDefault();
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
                                                            );
                                                        }}
                                                    >
                                                        <TeamPicker
                                                            teams={teams}
                                                            selected={
                                                                sharing.data
                                                                    .team_ids
                                                            }
                                                            onChange={(ids) =>
                                                                sharing.setData(
                                                                    'team_ids',
                                                                    ids,
                                                                )
                                                            }
                                                        />
                                                        <ErrorMessage>
                                                            {Object.values(
                                                                sharing.errors,
                                                            ).join(' ')}
                                                        </ErrorMessage>
                                                        {teams.length === 0 && (
                                                            <p className="text-muted-foreground">
                                                                You have no
                                                                current team
                                                                memberships. Ask
                                                                an admin to add
                                                                you to a team.
                                                            </p>
                                                        )}
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
                                                                    sharing.processing ||
                                                                    !sharing
                                                                        .data
                                                                        .team_ids
                                                                        .length
                                                                }
                                                                type="submit"
                                                            >
                                                                Save changes
                                                            </Button>
                                                        </DialogFooter>
                                                    </form>
                                                </DialogContent>
                                            </Dialog>
                                        )}
                                </div>
                                {transfer.teams.length ? (
                                    <div className="flex flex-wrap gap-2">
                                        {transfer.teams.map((team) => (
                                            <span
                                                key={team.id}
                                                className="inline-flex flex-wrap items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-2 text-sm"
                                            >
                                                <strong className="wrap-anywhere">
                                                    {team.name}
                                                </strong>
                                                <span className="text-xs text-muted-foreground">
                                                    {team.users_count} members
                                                </span>
                                            </span>
                                        ))}
                                    </div>
                                ) : (
                                    <ErrorMessage>
                                        No teams remain. Change teams to restore
                                        recipient access.
                                    </ErrorMessage>
                                )}
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
                                        <ErrorMessage>
                                            {Object.values(
                                                deletion.errors,
                                            ).join(' ')}
                                        </ErrorMessage>
                                        <DialogFooter>
                                            <Button
                                                variant="outline"
                                                onClick={() =>
                                                    setDeleteOpen(false)
                                                }
                                            >
                                                Keep transfer
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                disabled={deletion.processing}
                                                onClick={() =>
                                                    deletion.delete(
                                                        destroy.url(
                                                            transfer.id,
                                                        ),
                                                    )
                                                }
                                            >
                                                Delete transfer
                                            </Button>
                                        </DialogFooter>
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
