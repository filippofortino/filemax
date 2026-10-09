import {
    Cancel01Icon,
    Globe02Icon,
    Tick02Icon,
    Upload01Icon,
    UserGroupIcon,
    ViewIcon,
    ViewOffIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type DragEvent } from 'react';
import {
    CopyLink,
    ErrorMessage,
    FileRow,
    PasswordBadge,
    Shell,
    TeamBadges,
    TeamPicker,
} from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { bytes, dateTime } from '@/lib/format';
import { request, uploadPart } from '@/lib/http';
import type { Team, Transfer, TransferFile } from '@/lib/types';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { destroy, show, store, update } from '@/routes/transfers';
import { complete, finalize, remove, sign } from '@/routes/transfers/uploads';

const UPLOAD_CONCURRENCY = 4;

type Entry = {
    key: string;
    file: File;
    remote?: TransferFile;
    status: 'waiting' | 'uploading' | 'done' | 'failed';
    loaded: number;
    error?: string;
};
export default function Create({ teams }: { teams: Team[] }) {
    const [entries, setEntries] = useState<Entry[]>([]);
    const currentEntries = useRef<Entry[]>([]);
    const draft = useRef<Transfer | null>(null);
    const abort = useRef<AbortController | null>(null);
    const upload = useRef<Promise<void> | null>(null);
    const operating = useRef(false);
    const cancelling = useRef(false);
    const picker = useRef<HTMLInputElement>(null);
    const statusHeading = useRef<HTMLHeadingElement>(null);
    const readyHeading = useRef<HTMLHeadingElement>(null);
    const fileList = useRef<HTMLDivElement>(null);
    const refocusRow = useRef<number | null>(null);
    const browseFiles = useRef<HTMLButtonElement>(null);
    const teamsTrigger = useRef<HTMLButtonElement>(null);
    const [title, setTitle] = useState('');
    const [message, setMessage] = useState('');
    const [visibility, setVisibility] = useState<'public' | 'teams'>('public');
    const [passwordProtected, setPasswordProtected] = useState(false);
    const [password, setPassword] = useState('');
    const [passwordVisible, setPasswordVisible] = useState(false);
    const [selectedTeams, setSelectedTeams] = useState<string[]>([]);
    const [expiry, setExpiry] = useState(7);
    const [busy, setBusy] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [error, setError] = useState('');
    const [validationFailed, setValidationFailed] = useState(false);
    const [ready, setReady] = useState<Transfer | null>(null);
    const [allFiles, setAllFiles] = useState(false);
    const [dragging, setDragging] = useState(false);
    const [remaining, setRemaining] = useState<number | null>(null);
    const dragDepth = useRef(0);
    function changeEntries(next: Entry[]) {
        currentEntries.current = next;
        setEntries(next);
    }
    function patch(key: string, values: Partial<Entry>) {
        changeEntries(
            currentEntries.current.map((entry) =>
                entry.key === key ? { ...entry, ...values } : entry,
            ),
        );
    }
    useEffect(() => {
        function warn(event: BeforeUnloadEvent) {
            if ((draft.current || operating.current) && !ready) {
                event.preventDefault();
            }
        }
        window.addEventListener('beforeunload', warn);
        const stopGuardingVisits = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (
                (draft.current || operating.current) &&
                !ready &&
                !(
                    visit.url.href === window.location.href &&
                    visit.preserveState === true
                )
            )
                return window.confirm(
                    'Leave this upload? You will need to select and upload these files again.',
                );
        });
        return () => {
            window.removeEventListener('beforeunload', warn);
            stopGuardingVisits();
            abort.current?.abort();
        };
    }, [ready]);
    useEffect(() => {
        if (ready && document.activeElement === document.body)
            readyHeading.current?.focus();
    }, [ready]);
    useEffect(() => {
        const index = refocusRow.current;
        if (busy || index === null) return;
        refocusRow.current = null;
        if (document.activeElement !== document.body) return;
        const rows = Array.from(fileList.current?.children ?? []);
        const target =
            [...rows.slice(index), ...rows.slice(0, index).reverse()]
                .map((row) => row.querySelector('button'))
                .find(Boolean) ??
            statusHeading.current ??
            browseFiles.current;
        target?.focus();
    }, [busy, entries]);
    function addFiles(files: FileList | File[]) {
        if (draft.current || busy || ready) return;
        changeEntries([
            ...currentEntries.current,
            ...Array.from(files).map((file) => ({
                key: crypto.randomUUID(),
                file,
                status: 'waiting' as const,
                loaded: 0,
            })),
        ]);
        setError('');
    }
    function drag(event: DragEvent) {
        event.preventDefault();
    }
    async function removeFile(entry: Entry) {
        if (operating.current) return;
        operating.current = true;
        refocusRow.current = currentEntries.current.findIndex(
            (item) => item.key === entry.key,
        );
        setRemoving(true);
        setBusy(true);
        setError('');
        try {
            if (draft.current && currentEntries.current.length === 1) {
                await request(destroy.url(draft.current.id), 'DELETE');
                draft.current = null;
            } else if (draft.current && entry.remote)
                await request(
                    remove.url({
                        transfer: draft.current.id,
                        file: entry.remote.id,
                    }),
                    'DELETE',
                );
            changeEntries(
                currentEntries.current.filter((item) => item.key !== entry.key),
            );
        } catch (cause) {
            setError((cause as Error).message);
        } finally {
            operating.current = false;
            setRemoving(false);
            setBusy(false);
        }
    }
    function send(started: number) {
        if (operating.current) return;
        const filesMissing = !currentEntries.current.length;
        const teamsMissing = visibility === 'teams' && !selectedTeams.length;
        setValidationFailed(filesMissing || teamsMissing);
        if (filesMissing) {
            browseFiles.current?.focus();
            return;
        }
        if (teamsMissing) {
            teamsTrigger.current?.focus();
            return;
        }
        operating.current = true;
        setBusy(true);
        statusHeading.current?.focus();
        setError('');
        setRemaining(null);
        const controller = new AbortController();
        abort.current = controller;
        upload.current = runUpload(controller, started);
    }
    async function runUpload(controller: AbortController, started: number) {
        let transferred = 0;
        try {
            if (!draft.current) {
                const result = await request<{ transfer: Transfer }>(
                    store.url(),
                    'POST',
                    {
                        title,
                        message,
                        visibility,
                        password_protected:
                            visibility === 'public' && passwordProtected,
                        ...(visibility === 'public' && passwordProtected
                            ? { password }
                            : {}),
                        team_ids: selectedTeams,
                        expires_in_days: expiry,
                        files: currentEntries.current.map((entry) => ({
                            name: entry.file.name,
                            size: entry.file.size,
                            type: entry.file.type,
                        })),
                    },
                );
                draft.current = result.transfer;
                setPassword('');
                setPasswordVisible(false);
                changeEntries(
                    currentEntries.current.map((entry, index) => ({
                        ...entry,
                        remote: result.transfer.files[index],
                    })),
                );
            }
            if (controller.signal.aborted) return;
            const transferId = draft.current.id;
            changeEntries(
                currentEntries.current.map((entry) =>
                    entry.status === 'done'
                        ? entry
                        : {
                              ...entry,
                              status: 'waiting',
                              loaded: 0,
                              error: undefined,
                          },
                ),
            );
            const files = currentEntries.current
                .filter((entry) => entry.status !== 'done')
                .map((entry) => ({
                    entry,
                    count: Math.max(
                        1,
                        Math.ceil(entry.file.size / entry.remote!.part_size),
                    ),
                    nextPart: 1,
                    completed: 0,
                    failed: false,
                    loaded: new Map<number, number>(),
                }));
            let nextFile = 0;
            function claimPart() {
                // ponytail: scan files per claim; use a ready-file queue if huge batches make this costly.
                for (let checked = 0; checked < files.length; checked++) {
                    const file = files[nextFile];
                    nextFile = (nextFile + 1) % files.length;
                    if (!file.failed && file.nextPart <= file.count) {
                        return { file, part: file.nextPart++ };
                    }
                }
            }
            async function worker() {
                while (!controller.signal.aborted) {
                    const job = claimPart();
                    if (!job) return;
                    const { file, part } = job;
                    const { entry } = file;
                    const remote = entry.remote!;
                    if (part === 1) {
                        patch(entry.key, { status: 'uploading' });
                    }
                    try {
                        const signed = await request<{
                            url: string;
                            headers: Record<string, string>;
                            completed: boolean;
                        }>(
                            sign.url({
                                transfer: transferId,
                                file: remote.id,
                                part,
                            }),
                            'POST',
                            undefined,
                            controller.signal,
                        );
                        if (controller.signal.aborted) return;
                        if (file.failed) continue;
                        const offset = (part - 1) * remote.part_size;
                        const blob = entry.file.slice(
                            offset,
                            Math.min(
                                offset + remote.part_size,
                                entry.file.size,
                            ),
                        );
                        function progress(loaded: number) {
                            if (controller.signal.aborted) return;
                            const previous = file.loaded.get(part) ?? 0;
                            const current = Math.max(
                                previous,
                                Math.min(blob.size, loaded),
                            );
                            file.loaded.set(part, current);
                            if (!signed.completed)
                                transferred += current - previous;
                            patch(entry.key, {
                                loaded: [...file.loaded.values()].reduce(
                                    (sum, bytes) => sum + bytes,
                                    0,
                                ),
                            });
                            const totalLoaded = currentEntries.current.reduce(
                                (sum, item) => sum + item.loaded,
                                0,
                            );
                            const elapsed = (Date.now() - started) / 1000;
                            if (elapsed > 2 && transferred > 0) {
                                setRemaining(
                                    Math.max(
                                        0,
                                        Math.round(
                                            (totalSize - totalLoaded) /
                                                (transferred / elapsed),
                                        ),
                                    ),
                                );
                            }
                        }
                        if (!signed.completed) {
                            await uploadPart(
                                signed.url,
                                signed.headers,
                                blob,
                                controller.signal,
                                progress,
                            );
                        }
                        if (controller.signal.aborted) return;
                        progress(blob.size);
                        file.completed++;
                        if (!file.failed && file.completed === file.count) {
                            await request(
                                complete.url({
                                    transfer: transferId,
                                    file: remote.id,
                                }),
                                'POST',
                                undefined,
                                controller.signal,
                            );
                            if (controller.signal.aborted) return;
                            patch(entry.key, {
                                status: 'done',
                                loaded: entry.file.size,
                            });
                        }
                    } catch (cause) {
                        if (controller.signal.aborted) return;
                        file.failed = true;
                        patch(entry.key, {
                            status: 'failed',
                            error: (cause as Error).message,
                        });
                    }
                }
            }
            await Promise.all(
                Array.from({ length: UPLOAD_CONCURRENCY }, worker),
            );
            if (controller.signal.aborted) return;
            if (
                currentEntries.current.some((entry) => entry.status !== 'done')
            ) {
                setError(
                    'Some files could not be uploaded. Completed files are safe — retry sends only what’s missing, or remove the failed files to finish with the rest.',
                );
                return;
            }
            if (visibility === 'teams')
                await request(
                    update.url(transferId),
                    'PATCH',
                    { team_ids: selectedTeams },
                    controller.signal,
                );
            if (controller.signal.aborted) return;
            const result = await request<{ transfer: Transfer }>(
                finalize.url(transferId),
                'POST',
                undefined,
                controller.signal,
            );
            if (!controller.signal.aborted) setReady(result.transfer);
        } catch (cause) {
            if (!controller.signal.aborted) {
                setError((cause as Error).message);
                if (visibility === 'teams') router.reload({ only: ['teams'] });
            }
        } finally {
            if (!cancelling.current) {
                operating.current = false;
                setBusy(false);
            }
            setRemaining(null);
        }
    }
    async function cancel() {
        if (cancelling.current || removing) return;
        if (
            !window.confirm(
                'Cancel this upload? Uploaded progress will be discarded. You will need to select and upload the files again.',
            )
        )
            return;
        cancelling.current = true;
        refocusRow.current = 0;
        operating.current = true;
        abort.current?.abort();
        setBusy(true);
        try {
            await upload.current;
            if (draft.current)
                await request(destroy.url(draft.current.id), 'DELETE');
            draft.current = null;
            changeEntries([]);
            setError('');
        } catch (cause) {
            setError((cause as Error).message);
        } finally {
            cancelling.current = false;
            operating.current = false;
            setBusy(false);
        }
    }
    const totalSize = entries.reduce((sum, entry) => sum + entry.file.size, 0);
    const loaded = entries.reduce((sum, entry) => sum + entry.loaded, 0);
    const percentage = totalSize
        ? Math.min(100, Math.round((loaded / totalSize) * 100))
        : entries.length && entries.every((entry) => entry.status === 'done')
          ? 100
          : 0;
    const availableUntil = new Date();
    availableUntil.setDate(availableUntil.getDate() + expiry);
    const hasDraft = entries.some((entry) => entry.remote !== undefined);
    const teamsHint = !selectedTeams.length && (
        <p
            id="teams-hint"
            className={cn(
                'text-sm text-muted-foreground',
                validationFailed && 'text-destructive',
            )}
        >
            Select at least one team.
        </p>
    );
    if (ready)
        return (
            <Shell>
                <Head title="Your link is ready" />
                <main className="flex flex-1 items-center justify-center bg-muted px-5 py-6 md:p-10">
                    <section
                        key="ready"
                        className="flex w-full max-w-2xl flex-col gap-6 rounded-xl border bg-background px-5 py-7 transition-[opacity,translate] duration-300 ease-out md:p-10 starting:opacity-0 motion-safe:starting:translate-y-2"
                    >
                        <div className="flex flex-col items-center gap-3.5 text-center">
                            <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary transition-[opacity,scale] [transition-delay:80ms] duration-300 ease-out starting:opacity-0 motion-safe:starting:scale-90">
                                <HugeiconsIcon
                                    icon={Tick02Icon}
                                    size={30}
                                    aria-hidden="true"
                                />
                            </span>
                            <h1
                                ref={readyHeading}
                                tabIndex={-1}
                                className="text-4xl"
                            >
                                Your link is ready
                            </h1>
                            <p className="text-muted-foreground">
                                {ready.files.length}{' '}
                                {ready.files.length === 1 ? 'file' : 'files'} ·{' '}
                                {bytes(ready.total_size)} · expires{' '}
                                {dateTime(ready.expires_at)}
                            </p>
                        </div>
                        <CopyLink url={ready.url} />
                        {ready.visibility === 'teams' ? (
                            <div className="flex flex-col gap-2 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-slate-700">
                                <div className="flex flex-wrap items-center gap-2">
                                    <strong>Shared with</strong>
                                    <TeamBadges teams={ready.teams} />
                                </div>
                                <p className="text-sm">
                                    Only signed-in, verified members of one of
                                    these teams can download.
                                </p>
                            </div>
                        ) : (
                            <div className="flex flex-col gap-2 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-slate-700">
                                {ready.password_protected && <PasswordBadge />}
                                <p>
                                    {ready.password_protected
                                        ? 'Anyone with the link and password. No account needed.'
                                        : 'Anyone with the link. No account needed.'}
                                </p>
                                {ready.password_protected && (
                                    <p className="text-sm">
                                        The password can’t be changed, removed
                                        or recovered. Send it separately from
                                        the link.
                                    </p>
                                )}
                            </div>
                        )}
                        <div>
                            {ready.files
                                .slice(0, allFiles ? undefined : 4)
                                .map((file) => (
                                    <FileRow
                                        key={file.id}
                                        name={file.original_name}
                                        size={file.size}
                                    />
                                ))}
                            {ready.files.length > 4 && (
                                <Button
                                    className="mt-3 h-auto min-h-0 p-0 text-sm"
                                    variant="link"
                                    onClick={() => setAllFiles(!allFiles)}
                                >
                                    {allFiles
                                        ? 'Show fewer files'
                                        : `Show all ${ready.files.length} files`}
                                </Button>
                            )}
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <Link
                                href={show(ready.id)}
                                className="font-semibold"
                            >
                                View transfer
                            </Link>
                            <Link
                                className={cn(
                                    buttonVariants({
                                        variant: 'outline',
                                    }),
                                )}
                                href={home()}
                                onClick={() => {
                                    setReady(null);
                                    draft.current = null;
                                    changeEntries([]);
                                    setTitle('');
                                    setMessage('');
                                    setPasswordProtected(false);
                                    setPassword('');
                                    setPasswordVisible(false);
                                }}
                            >
                                Send another
                            </Link>
                        </div>
                    </section>
                </main>
            </Shell>
        );
    return (
        <Shell>
            <Head title="New transfer" />
            <main
                className="grid flex-1 grid-cols-1 md:grid-cols-2"
                onDragEnter={(event) => {
                    drag(event);
                    if (event.dataTransfer.types.includes('Files')) {
                        dragDepth.current++;
                        setDragging(true);
                    }
                }}
                onDragOver={drag}
                onDragLeave={(event) => {
                    drag(event);
                    dragDepth.current--;
                    if (dragDepth.current <= 0) setDragging(false);
                }}
                onDrop={(event) => {
                    drag(event);
                    dragDepth.current = 0;
                    setDragging(false);
                    addFiles(event.dataTransfer.files);
                }}
            >
                <section className="flex min-w-0 flex-col gap-6 border-b bg-muted px-5 py-6 md:border-r md:border-b-0 md:p-8 lg:p-10">
                    <input
                        ref={picker}
                        type="file"
                        multiple
                        hidden
                        onChange={(event) => {
                            if (event.target.files)
                                addFiles(event.target.files);
                            event.target.value = '';
                        }}
                    />
                    {!entries.length ? (
                        <div className="flex min-h-72 flex-1 flex-col items-center justify-center gap-4 text-center md:min-h-80">
                            <span className="inline-flex size-18 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <HugeiconsIcon
                                    icon={Upload01Icon}
                                    size={32}
                                    strokeWidth={2}
                                    aria-hidden="true"
                                />
                            </span>
                            <h1 className="text-4xl md:text-5xl">
                                Drop files here
                            </h1>
                            <p className="text-base text-muted-foreground">
                                Anywhere on this page works.
                            </p>
                            <Button
                                ref={browseFiles}
                                variant="outline"
                                aria-describedby="submit-hint"
                                onClick={() => picker.current?.click()}
                            >
                                Browse files
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="flex flex-col gap-3 rounded-xl border bg-background p-6">
                                <div className="flex items-baseline justify-between gap-3">
                                    <h1
                                        ref={statusHeading}
                                        tabIndex={-1}
                                        className="text-3xl"
                                    >
                                        {busy
                                            ? 'Uploading…'
                                            : hasDraft
                                              ? entries.some(
                                                    (entry) =>
                                                        entry.status ===
                                                        'failed',
                                                )
                                                  ? 'Upload incomplete'
                                                  : 'Ready to finish'
                                              : 'Ready to send'}
                                    </h1>
                                    {hasDraft && (
                                        <strong className="font-heading text-3xl text-primary">
                                            {percentage}%
                                        </strong>
                                    )}
                                </div>
                                {hasDraft ? (
                                    <>
                                        <progress
                                            aria-label="Overall upload progress"
                                            value={percentage}
                                            max={100}
                                        />
                                        <div className="flex justify-between gap-2 text-sm text-muted-foreground">
                                            <span>
                                                {bytes(loaded)} of{' '}
                                                {bytes(totalSize)}
                                            </span>
                                            {remaining !== null && (
                                                <span>
                                                    About{' '}
                                                    {remaining < 60
                                                        ? `${remaining} ${remaining === 1 ? 'second' : 'seconds'}`
                                                        : `${Math.ceil(remaining / 60)} ${Math.ceil(remaining / 60) === 1 ? 'minute' : 'minutes'}`}{' '}
                                                    left
                                                </span>
                                            )}
                                        </div>
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        {entries.length}{' '}
                                        {entries.length === 1
                                            ? 'file'
                                            : 'files'}{' '}
                                        · {bytes(totalSize)}
                                    </p>
                                )}
                            </div>
                            <div ref={fileList} className="flex flex-col gap-2">
                                {entries.map((entry) => (
                                    <div
                                        className="overflow-hidden rounded-lg border bg-background px-3"
                                        key={entry.key}
                                    >
                                        <FileRow
                                            name={entry.file.name}
                                            size={entry.file.size}
                                            variant="upload"
                                        >
                                            {entry.status === 'done' ? (
                                                <span className="flex items-center gap-1 text-sm font-semibold text-emerald-700">
                                                    <HugeiconsIcon
                                                        icon={Tick02Icon}
                                                        size={16}
                                                        aria-hidden="true"
                                                    />
                                                    Done
                                                </span>
                                            ) : entry.status === 'failed' ? (
                                                <span className="text-sm text-destructive">
                                                    Failed
                                                </span>
                                            ) : entry.status === 'uploading' ? (
                                                <span className="text-sm text-primary">
                                                    {entry.file.size
                                                        ? Math.min(
                                                              100,
                                                              Math.round(
                                                                  (entry.loaded /
                                                                      entry.file
                                                                          .size) *
                                                                      100,
                                                              ),
                                                          )
                                                        : 0}
                                                    %
                                                </span>
                                            ) : hasDraft ? (
                                                <span className="text-sm text-muted-foreground">
                                                    Waiting
                                                </span>
                                            ) : null}
                                            {!busy &&
                                                entry.status !== 'done' && (
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Remove ${entry.file.name}`}
                                                        onClick={() =>
                                                            void removeFile(
                                                                entry,
                                                            )
                                                        }
                                                    >
                                                        <HugeiconsIcon
                                                            icon={Cancel01Icon}
                                                            size={16}
                                                            aria-hidden="true"
                                                        />
                                                    </Button>
                                                )}
                                        </FileRow>
                                        {entry.error && (
                                            <p className="pb-3 text-sm leading-normal wrap-anywhere text-destructive">
                                                {entry.error}
                                            </p>
                                        )}
                                        {entry.status === 'uploading' && (
                                            <progress
                                                className="h-1"
                                                aria-label={`Uploading ${entry.file.name}`}
                                                value={entry.loaded}
                                                max={Math.max(
                                                    entry.file.size,
                                                    1,
                                                )}
                                            />
                                        )}
                                    </div>
                                ))}
                            </div>
                            {!hasDraft && (
                                <Button
                                    variant="outline"
                                    onClick={() => picker.current?.click()}
                                >
                                    Add more files
                                </Button>
                            )}
                            {busy && hasDraft && (
                                <p className="text-center text-sm text-muted-foreground">
                                    Keep this tab open until the upload
                                    finishes.
                                </p>
                            )}
                        </>
                    )}
                </section>
                <form
                    className="flex min-w-0 flex-col gap-6 px-5 py-7 md:px-8 md:py-9 lg:gap-7 lg:px-16 lg:py-12"
                    onSubmit={(event) => {
                        event.preventDefault();
                        send(Date.now());
                    }}
                >
                    <h2>Transfer details</h2>
                    {hasDraft ? (
                        <>
                            <div className="flex flex-col gap-2">
                                <span className="text-sm font-semibold">
                                    Title
                                </span>
                                <div className="rounded-md border bg-muted px-3.5 py-3 text-base wrap-anywhere">
                                    {title || entries[0]?.file.name}
                                </div>
                            </div>
                            <div className="flex flex-col gap-2">
                                <span className="text-sm font-semibold">
                                    Message
                                </span>
                                <div className="rounded-md border bg-muted px-3.5 py-3 text-base wrap-anywhere whitespace-pre-wrap">
                                    {message || 'No message'}
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-1 lg:grid-cols-2">
                                <div className="flex flex-col gap-2.5">
                                    <span className="text-sm font-semibold">
                                        Who can download
                                    </span>
                                    {visibility === 'teams' ? (
                                        !busy ? (
                                            <>
                                                <TeamPicker
                                                    teams={teams}
                                                    selected={selectedTeams}
                                                    onChange={setSelectedTeams}
                                                    triggerRef={teamsTrigger}
                                                    describedBy={
                                                        selectedTeams.length
                                                            ? undefined
                                                            : 'teams-hint'
                                                    }
                                                />
                                                {teamsHint}
                                            </>
                                        ) : (
                                            <TeamBadges
                                                teams={teams.filter((team) =>
                                                    selectedTeams.includes(
                                                        team.id,
                                                    ),
                                                )}
                                            />
                                        )
                                    ) : (
                                        <span className="flex w-fit items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-slate-700">
                                            <HugeiconsIcon
                                                icon={Globe02Icon}
                                                size={18}
                                                aria-hidden="true"
                                            />
                                            Public link
                                        </span>
                                    )}
                                </div>
                                <div className="flex flex-col gap-2.5">
                                    <span className="text-sm font-semibold">
                                        Link expires
                                    </span>
                                    <span className="rounded-md border bg-muted px-3.5 py-3 text-base">
                                        {expiry} {expiry === 1 ? 'day' : 'days'}
                                    </span>
                                </div>
                            </div>
                            {draft.current?.password_protected && (
                                <div className="flex flex-col gap-2">
                                    <PasswordBadge />
                                    <p className="text-sm text-muted-foreground">
                                        The password can’t be changed, removed
                                        or recovered.
                                    </p>
                                </div>
                            )}
                        </>
                    ) : (
                        <>
                            <div className="flex flex-col gap-2">
                                <label
                                    className="text-sm font-semibold"
                                    htmlFor="transfer-title"
                                >
                                    Title{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </label>
                                <input
                                    id="transfer-title"
                                    placeholder="What is this?"
                                    maxLength={255}
                                    value={title}
                                    onChange={(event) =>
                                        setTitle(event.target.value)
                                    }
                                    disabled={busy || hasDraft}
                                />
                            </div>
                            <div className="flex flex-col gap-2">
                                <label
                                    className="text-sm font-semibold"
                                    htmlFor="transfer-message"
                                >
                                    Message{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </label>
                                <textarea
                                    id="transfer-message"
                                    rows={3}
                                    placeholder="A note for whoever opens the link"
                                    value={message}
                                    onChange={(event) =>
                                        setMessage(event.target.value)
                                    }
                                    disabled={busy || hasDraft}
                                />
                            </div>
                            <fieldset className="flex flex-col gap-2.5">
                                <legend className="mb-2.5 text-sm font-semibold">
                                    Who can download
                                </legend>
                                <div className="grid grid-cols-2 gap-3">
                                    {(
                                        [
                                            {
                                                value: 'public',
                                                icon: Globe02Icon,
                                                label: 'Public link',
                                                text: passwordProtected
                                                    ? 'Anyone with the link and the password.'
                                                    : 'Anyone with the link. No account needed.',
                                            },
                                            {
                                                value: 'teams',
                                                icon: UserGroupIcon,
                                                label: 'Specific teams',
                                                text: 'Signed-in members of the teams you pick.',
                                            },
                                        ] as const
                                    ).map((option) => (
                                        <label
                                            className="flex cursor-pointer flex-col gap-2 rounded-lg border border-input p-4 has-checked:border-primary has-checked:bg-primary/5 has-checked:ring-1 has-checked:ring-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed has-disabled:bg-muted has-disabled:text-muted-foreground"
                                            key={option.value}
                                        >
                                            <span className="flex items-center justify-between has-checked:text-primary">
                                                <HugeiconsIcon
                                                    icon={option.icon}
                                                    size={22}
                                                    aria-hidden="true"
                                                />
                                                <input
                                                    type="radio"
                                                    name="visibility"
                                                    value={option.value}
                                                    checked={
                                                        visibility ===
                                                        option.value
                                                    }
                                                    disabled={
                                                        busy ||
                                                        hasDraft ||
                                                        (option.value ===
                                                            'teams' &&
                                                            teams.length === 0)
                                                    }
                                                    onChange={() => {
                                                        setVisibility(
                                                            option.value,
                                                        );
                                                        if (
                                                            option.value ===
                                                            'public'
                                                        )
                                                            setSelectedTeams(
                                                                [],
                                                            );
                                                        else {
                                                            setPasswordProtected(
                                                                false,
                                                            );
                                                            setPassword('');
                                                            setPasswordVisible(
                                                                false,
                                                            );
                                                        }
                                                    }}
                                                />
                                            </span>
                                            <span>
                                                <strong
                                                    className={cn(
                                                        'text-base',
                                                        visibility ===
                                                            option.value &&
                                                            'text-primary',
                                                    )}
                                                >
                                                    {option.label}
                                                </strong>
                                                <p className="text-sm text-muted-foreground">
                                                    {option.text}
                                                </p>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {teams.length
                                        ? `You belong to ${teams.length} ${teams.length === 1 ? 'team' : 'teams'}: ${teams.map((team) => team.name).join(', ')}.`
                                        : 'No team memberships yet. You can share public links, or ask an admin to add you to a team.'}
                                </p>
                                {visibility === 'public' && (
                                    <div className="flex flex-col gap-4 rounded-lg border border-input p-4">
                                        <div className="flex items-start justify-between gap-6">
                                            <div className="flex flex-col gap-1">
                                                <span
                                                    id="protect-label"
                                                    className="font-semibold"
                                                >
                                                    Require a password
                                                </span>
                                                <p
                                                    id="protect-hint"
                                                    className="text-sm text-muted-foreground"
                                                >
                                                    Anyone opening the link
                                                    types it before they see any
                                                    file.
                                                </p>
                                            </div>
                                            <Switch
                                                id="password-protected"
                                                checked={passwordProtected}
                                                disabled={busy}
                                                aria-labelledby="protect-label"
                                                aria-describedby="protect-hint"
                                                onCheckedChange={(checked) => {
                                                    setPasswordProtected(
                                                        checked,
                                                    );
                                                    setPassword('');
                                                    setPasswordVisible(false);
                                                }}
                                            />
                                        </div>
                                        {passwordProtected && (
                                            <div className="flex flex-col gap-2">
                                                <label
                                                    className="text-sm font-semibold"
                                                    htmlFor="transfer-password"
                                                >
                                                    Password
                                                </label>
                                                <div className="relative">
                                                    <input
                                                        id="transfer-password"
                                                        type={
                                                            passwordVisible
                                                                ? 'text'
                                                                : 'password'
                                                        }
                                                        autoComplete="new-password"
                                                        spellCheck={false}
                                                        minLength={8}
                                                        maxLength={72}
                                                        required
                                                        disabled={busy}
                                                        aria-describedby="transfer-password-hint"
                                                        className="pr-12"
                                                        value={password}
                                                        onChange={(event) =>
                                                            setPassword(
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        className="absolute top-0 right-0 text-muted-foreground"
                                                        aria-label={
                                                            passwordVisible
                                                                ? 'Hide password'
                                                                : 'Show password'
                                                        }
                                                        aria-pressed={
                                                            passwordVisible
                                                        }
                                                        disabled={busy}
                                                        onClick={() =>
                                                            setPasswordVisible(
                                                                !passwordVisible,
                                                            )
                                                        }
                                                    >
                                                        <HugeiconsIcon
                                                            icon={
                                                                passwordVisible
                                                                    ? ViewOffIcon
                                                                    : ViewIcon
                                                            }
                                                            size={20}
                                                            aria-hidden="true"
                                                        />
                                                    </Button>
                                                </div>
                                                <p
                                                    id="transfer-password-hint"
                                                    className="text-sm text-muted-foreground"
                                                >
                                                    At least 8 characters. Send
                                                    it separately, by phone or
                                                    chat, not in the same email
                                                    as the link. The password
                                                    can’t be changed, removed or
                                                    recovered.
                                                </p>
                                            </div>
                                        )}
                                    </div>
                                )}
                                {visibility === 'teams' && (
                                    <>
                                        <TeamPicker
                                            teams={teams}
                                            selected={selectedTeams}
                                            onChange={setSelectedTeams}
                                            disabled={busy}
                                            triggerRef={teamsTrigger}
                                            describedBy={
                                                selectedTeams.length
                                                    ? undefined
                                                    : 'teams-hint'
                                            }
                                        />
                                        {teamsHint}
                                    </>
                                )}
                            </fieldset>
                            <fieldset>
                                <legend className="mb-2.5 text-sm font-semibold">
                                    Link expires
                                </legend>
                                <div className="flex flex-wrap gap-2">
                                    {[1, 7, 14, 30].map((days) => (
                                        <label
                                            key={days}
                                            className="inline-flex h-10 cursor-pointer items-center rounded-full border border-input px-3 has-checked:border-primary has-checked:bg-primary/5 has-checked:font-semibold has-checked:text-primary has-checked:ring-1 has-checked:ring-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed"
                                        >
                                            <input
                                                type="radio"
                                                className="sr-only"
                                                name="expiry"
                                                checked={expiry === days}
                                                onChange={() => setExpiry(days)}
                                                disabled={busy || hasDraft}
                                            />
                                            {days} {days === 1 ? 'day' : 'days'}
                                        </label>
                                    ))}
                                </div>
                                <p className="mt-2.5 text-sm text-muted-foreground">
                                    Available until{' '}
                                    {dateTime(availableUntil.toISOString())}.
                                </p>
                            </fieldset>
                        </>
                    )}
                    <div className="flex flex-col gap-3">
                        <ErrorMessage>{error}</ErrorMessage>
                        <Button
                            size="lg"
                            type="submit"
                            className={cn(busy && hasDraft && 'hidden')}
                            disabled={busy}
                            aria-describedby="submit-hint"
                        >
                            {busy
                                ? 'Uploading…'
                                : hasDraft
                                  ? 'Retry and create link'
                                  : 'Create transfer'}
                        </Button>
                        {hasDraft && (
                            <Button
                                variant="outline"
                                size="lg"
                                type="button"
                                disabled={removing}
                                onClick={() => void cancel()}
                            >
                                Cancel upload
                            </Button>
                        )}
                        <p
                            id="submit-hint"
                            className={cn(
                                'text-center text-sm text-muted-foreground',
                                validationFailed &&
                                    !entries.length &&
                                    'text-destructive',
                            )}
                        >
                            {!entries.length
                                ? 'Add at least one file to continue'
                                : busy
                                  ? 'Your link appears as soon as the last file lands.'
                                  : 'Your files stay private until your link is ready.'}
                        </p>
                    </div>
                </form>
                {dragging && !hasDraft && (
                    <div className="pointer-events-none fixed inset-3 z-50 flex items-center justify-center rounded-xl border-2 border-dashed border-primary bg-accent/95 p-6 text-center font-heading text-4xl transition-opacity duration-150 ease-out starting:opacity-0">
                        Drop to add your files
                    </div>
                )}
            </main>
        </Shell>
    );
}
