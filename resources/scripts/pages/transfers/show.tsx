import {
    ArrowLeft01Icon,
    Delete02Icon,
    Globe02Icon,
    UserGroupIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FileDownload } from '@/components/downloads';
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
import { bytes, date } from '@/lib/format';
import type { Team, Transfer } from '@/lib/types';
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
            <main className="page-content owner-detail">
                <Link
                    href={index()}
                    className="mb-6 inline-flex items-center gap-1.5 text-[13px] font-semibold"
                >
                    <HugeiconsIcon
                        icon={ArrowLeft01Icon}
                        size={16}
                        aria-hidden="true"
                    />
                    My transfers
                </Link>
                <div className="page-heading detail-heading">
                    <div className="detail-title-row">
                        <h1>{transfer.title}</h1>
                        <span
                            className="detail-visibility"
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
                            <span className="status inactive">
                                {transfer.revoked_at ? 'Deleted' : 'Expired'}
                            </span>
                        )}
                    </div>
                    <p className="muted">
                        Created {date(transfer.created_at)} · Expires{' '}
                        {date(transfer.expires_at)} · {transfer.files_count}{' '}
                        {transfer.files_count === 1 ? 'file' : 'files'} ·{' '}
                        {bytes(transfer.total_size)}
                    </p>
                </div>
                <div className="detail-grid">
                    <section className="detail-panel">
                        {transfer.url && <CopyLink url={transfer.url} />}
                        {transfer.visibility === 'teams' && (
                            <section className="detail-sharing">
                                <div className="flex items-center justify-between gap-3">
                                    <h2 className="detail-section-title">
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
                                                <DialogTrigger asChild>
                                                    <Button
                                                        variant="link"
                                                        size="sm"
                                                    >
                                                        Change teams
                                                    </Button>
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
                                                        className="form-stack"
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
                                                            <p className="muted">
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
                                    <div className="detail-shared-teams">
                                        {transfer.teams.map((team) => (
                                            <span
                                                key={team.id}
                                                className="detail-team"
                                            >
                                                <strong>{team.name}</strong>
                                                <span>
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
                                <p className="muted text-[13px]">
                                    Members sign in to download. The link alone
                                    is not enough.
                                </p>
                            </section>
                        )}
                        <section className="detail-message">
                            <h2 className="detail-section-title">Message</h2>
                            {transfer.message ? (
                                <p>{transfer.message}</p>
                            ) : (
                                <p className="detail-no-message">No message.</p>
                            )}
                        </section>
                        <section>
                            <div className="detail-files-heading">
                                <h2 className="detail-section-title">
                                    Files
                                    {transfer.files.length > 4 && !allFiles && (
                                        <span className="muted font-normal">
                                            {' '}
                                            · showing 4 of{' '}
                                            {transfer.files.length}
                                        </span>
                                    )}
                                </h2>
                                <span className="muted text-[13px]">
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
                                        >
                                            {transfer.available && (
                                                <FileDownload
                                                    token={transfer.token}
                                                    fileId={file.id}
                                                    name={file.original_name}
                                                    onDownload={refresh}
                                                />
                                            )}
                                            <span
                                                className="detail-click-count"
                                                title="Download-button actions"
                                            >
                                                {file.download_count || '—'}
                                            </span>
                                        </FileRow>
                                    ))}
                            </div>
                            {transfer.files.length > 4 && (
                                <Button
                                    className="detail-show-files"
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
                    <aside className="detail-sidebar">
                        <div className="detail-activity">
                            <h2
                                className="detail-section-title muted"
                                title="Counts download-button clicks, including your own."
                            >
                                Downloads
                            </h2>
                            <div className="detail-download-total">
                                <strong
                                    className={`download-stat${transfer.download_count === 0 ? ' muted' : ''}`}
                                >
                                    {transfer.download_count}
                                </strong>
                                {transfer.download_count > 0 && (
                                    <span className="muted text-[13px]">
                                        total
                                    </span>
                                )}
                            </div>
                            <div className="detail-activity-dates">
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
                        <div className="detail-actions flex flex-col gap-2">
                            {!transfer.revoked_at && (
                                <Dialog
                                    open={deleteOpen}
                                    onOpenChange={setDeleteOpen}
                                >
                                    <DialogTrigger asChild>
                                        <Button variant="destructive">
                                            <HugeiconsIcon
                                                icon={Delete02Icon}
                                                size={18}
                                                aria-hidden="true"
                                            />
                                            Delete transfer
                                        </Button>
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
                            <p className="muted px-1 pt-1 text-center text-[12px]">
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
