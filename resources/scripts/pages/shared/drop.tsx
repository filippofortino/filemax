import {
    Cancel01Icon,
    Clock01Icon,
    File01Icon,
    Image01Icon,
    Tick02Icon,
    Unlink01Icon,
    Upload01Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, router } from '@inertiajs/react';
import {
    useEffect,
    useRef,
    useState,
    type DragEvent,
    type FormEvent,
} from 'react';
import { Avatar } from '@/components/avatar';
import { ErrorMessage, FileRow, Shell } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { acceptedList, rejectReason, typesLabel } from '@/lib/drop';
import { bytes, date, dateTime } from '@/lib/format';
import { request } from '@/lib/http';
import type { DropRules, TransferFile } from '@/lib/types';
import { uploadEntries, type UploadEntry } from '@/lib/upload';
import { cn } from '@/lib/utils';
import { store } from '@/routes/shared/drops/submissions';
import {
    cancel,
    complete,
    finalize,
    remove,
    sign,
} from '@/routes/shared/drops/uploads';

type SharedDrop = DropRules & {
    token: string;
    title: string;
    instructions: string | null;
    /** Null when the owner hides their name, as on transfers and secrets. */
    owner: { name: string; avatar_url: string | null } | null;
    open: boolean;
    closes_at: string;
    closed_at: string;
    closed_early: boolean;
    max_file_size: number;
    accepted_extensions: string[];
};
/** `sending` marks the files posted with the submission; the others don't fit the Drop's rules. */
type Entry = UploadEntry & { remote?: TransferFile; sending?: boolean };
type Sent = { files: Entry[]; time: string; open: boolean; closesAt: string };

const START_AGAIN = 'This upload can’t continue. Start again.';
const SOME_FAILED =
    'Some files could not be uploaded. Completed files are safe — retry sends only what’s missing, or remove the failed files to send the rest.';

/** The time of day, as "today, 11:02" shows it. */
function clockTime(): string {
    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date());
}

/** Why each file won't be sent, or ''. Only accepted files count towards the total, in list order. */
function rejections(entries: Entry[], drop: SharedDrop): string[] {
    let acceptedSize = 0;
    return entries.map((entry) => {
        const reason = rejectReason(entry.file, drop, acceptedSize);
        if (!reason) acceptedSize += entry.file.size;
        return reason;
    });
}

export default function SharedDropPage({ drop }: { drop: SharedDrop | null }) {
    const first = drop?.owner?.name.split(' ')[0];
    const closedHeading = useRef<HTMLHeadingElement>(null);
    const wasOpen = useRef(drop?.open);
    // A Drop that closes mid-send reloads into the closed screen, and the pressed button is gone.
    useEffect(() => {
        if (
            wasOpen.current &&
            !drop?.open &&
            document.activeElement === document.body
        )
            closedHeading.current?.focus();
        wasOpen.current = drop?.open;
    }, [drop?.open]);
    return (
        <Shell
            recipient
            footer="Requested with Filemax, the file-sharing tool of Mediamax Communication."
        >
            <Head title={drop?.title ?? 'Drop unavailable'}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            {drop?.open ? (
                <Uploader drop={drop} />
            ) : (
                <main className="flex flex-1 items-center justify-center px-5 py-6 md:px-10 md:pb-2">
                    <section className="flex w-full max-w-lg min-w-0 flex-col items-center gap-4 text-center md:rounded-xl md:border md:bg-background md:px-10 md:py-11">
                        <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <HugeiconsIcon
                                icon={drop ? Clock01Icon : Unlink01Icon}
                                size={28}
                                strokeWidth={2}
                                aria-hidden="true"
                            />
                        </span>
                        {drop ? (
                            <>
                                <h1 ref={closedHeading} tabIndex={-1}>
                                    This Drop is closed
                                </h1>
                                <p className="max-w-sm text-base text-muted-foreground">
                                    {first
                                        ? `It reached the deadline ${first} set, or ${first} closed it early. Ask ${first} for a new link if you still need to send files.`
                                        : 'It reached its deadline, or it was closed early. Ask whoever sent you this link for a new one if you still need to send files.'}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {drop.owner
                                        ? `Requested by ${drop.owner.name} · closed ${date(drop.closed_at)}`
                                        : `Closed ${date(drop.closed_at)}`}
                                </p>
                            </>
                        ) : (
                            <>
                                <h1>This link isn’t working</h1>
                                <p className="max-w-sm text-base text-muted-foreground">
                                    Check it, or ask whoever sent it for a new
                                    one.
                                </p>
                            </>
                        )}
                    </section>
                </main>
            )}
        </Shell>
    );
}

function Uploader({ drop }: { drop: SharedDrop }) {
    const [entries, setEntries] = useState<Entry[]>([]);
    const currentEntries = useRef<Entry[]>([]);
    const submission = useRef<string | null>(null);
    const abort = useRef<AbortController | null>(null);
    const upload = useRef<Promise<void> | null>(null);
    const operating = useRef(false);
    const cancelling = useRef(false);
    const picker = useRef<HTMLInputElement>(null);
    const browseFiles = useRef<HTMLButtonElement>(null);
    const nameInput = useRef<HTMLInputElement>(null);
    const screenHeading = useRef<HTMLHeadingElement>(null);
    const focusHeading = useRef(false);
    const fileList = useRef<HTMLUListElement>(null);
    const refocusRow = useRef<number | null>(null);
    const dragDepth = useRef(0);
    const [name, setName] = useState('');
    const [message, setMessage] = useState('');
    const [tried, setTried] = useState(false);
    const [busy, setBusy] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [error, setError] = useState('');
    const [remaining, setRemaining] = useState<number | null>(null);
    const [dragging, setDragging] = useState(false);
    const [sent, setSent] = useState<Sent | null>(null);
    const [allFiles, setAllFiles] = useState(false);
    const owner = drop.owner;
    // With the owner's name hidden, the copy talks about "the recipient" instead.
    const recipient = owner?.name.split(' ')[0] ?? 'the recipient';
    const sending = entries.filter((entry) => entry.sending);
    const screen = sent ? 'sent' : sending.length ? 'uploading' : 'form';
    const reasons = rejections(entries, drop);
    const accepted = entries.filter((entry, index) => !reasons[index]);
    const acceptedSize = accepted.reduce(
        (sum, entry) => sum + entry.file.size,
        0,
    );
    const rejected = entries.length - accepted.length;
    const nameError = tried && !name.trim();
    const types = typesLabel(drop);
    const totalSize = sending.reduce((sum, entry) => sum + entry.file.size, 0);
    const loaded = sending.reduce((sum, entry) => sum + entry.loaded, 0);
    const percentage = totalSize
        ? Math.min(100, Math.round((loaded / totalSize) * 100))
        : sending.length && sending.every((entry) => entry.status === 'done')
          ? 100
          : 0;
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
            if (submission.current || operating.current) {
                event.preventDefault();
            }
        }
        window.addEventListener('beforeunload', warn);
        const stopGuardingVisits = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (
                (submission.current || operating.current) &&
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
    }, []);
    // When a screen swaps in, the pressed button is gone: move focus to the new heading.
    useEffect(() => {
        if (!focusHeading.current) return;
        focusHeading.current = false;
        if (document.activeElement === document.body)
            screenHeading.current?.focus();
    }, [screen]);
    useEffect(() => {
        const index = refocusRow.current;
        if (removing || index === null) return;
        refocusRow.current = null;
        if (document.activeElement !== document.body) return;
        const rows = Array.from(fileList.current?.children ?? []);
        const target =
            [...rows.slice(index), ...rows.slice(0, index).reverse()]
                .map((row) => row.querySelector('button'))
                .find(Boolean) ??
            browseFiles.current ??
            screenHeading.current;
        target?.focus();
    }, [removing, entries]);
    function addFiles(files: FileList) {
        if (screen !== 'form' || operating.current) return;
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
    /** Drops the submission and shows the form again with every picked file. */
    function backToForm(notice = '') {
        submission.current = null;
        changeEntries(
            currentEntries.current.map((entry) => ({
                key: entry.key,
                file: entry.file,
                status: 'waiting' as const,
                loaded: 0,
            })),
        );
        setError(notice);
        focusHeading.current = true;
    }
    function fail(cause: unknown) {
        const status = (cause as { status?: number }).status;
        if (status === 403 || status === 419) backToForm(START_AGAIN);
        else if (submission.current) setError((cause as Error).message);
        else backToForm((cause as Error).message);
    }
    function send(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (operating.current) return;
        setTried(true);
        if (!accepted.length) {
            browseFiles.current?.focus();
            return;
        }
        if (!name.trim()) {
            nameInput.current?.focus();
            return;
        }
        const reasons = rejections(currentEntries.current, drop);
        changeEntries(
            currentEntries.current.map((entry, index) =>
                reasons[index] ? entry : { ...entry, sending: true },
            ),
        );
        focusHeading.current = true;
        start(event.timeStamp);
    }
    /** `started` is an event's timeStamp, on the same clock as performance.now(). */
    function start(started: number) {
        operating.current = true;
        setBusy(true);
        setError('');
        setRemaining(null);
        const controller = new AbortController();
        abort.current = controller;
        upload.current = runUpload(controller, started);
    }
    async function runUpload(controller: AbortController, started: number) {
        let transferred = 0;
        try {
            if (!submission.current) {
                const posted = currentEntries.current.filter(
                    (entry) => entry.sending,
                );
                const result = await request<{
                    submission: { id: string; files: TransferFile[] };
                }>(store.url({ drop: drop.token }), 'POST', {
                    sender_name: name,
                    message,
                    files: posted.map((entry) => ({
                        name: entry.file.name,
                        size: entry.file.size,
                        type: entry.file.type,
                    })),
                });
                submission.current = result.submission.id;
                let position = 0;
                changeEntries(
                    currentEntries.current.map((entry) =>
                        entry.sending
                            ? {
                                  ...entry,
                                  remote: result.submission.files[position++],
                              }
                            : entry,
                    ),
                );
            }
            if (controller.signal.aborted) return;
            const transfer = submission.current;
            changeEntries(
                currentEntries.current.map((entry) =>
                    !entry.sending || entry.status === 'done'
                        ? entry
                        : {
                              ...entry,
                              status: 'waiting',
                              loaded: 0,
                              error: undefined,
                          },
                ),
            );
            await uploadEntries(
                currentEntries.current.filter(
                    (entry) => entry.sending && entry.status !== 'done',
                ),
                {
                    sign: (file, part) => sign.url({ transfer, file, part }),
                    complete: (file) => complete.url({ transfer, file }),
                },
                controller.signal,
                patch,
                (delta) => {
                    transferred += delta;
                    const posted = currentEntries.current.filter(
                        (entry) => entry.sending,
                    );
                    const total = posted.reduce(
                        (sum, entry) => sum + entry.file.size,
                        0,
                    );
                    const done = posted.reduce(
                        (sum, entry) => sum + entry.loaded,
                        0,
                    );
                    const elapsed = (performance.now() - started) / 1000;
                    if (elapsed > 2 && transferred > 0) {
                        setRemaining(
                            Math.max(
                                0,
                                Math.round(
                                    (total - done) / (transferred / elapsed),
                                ),
                            ),
                        );
                    }
                },
            );
            if (controller.signal.aborted) return;
            const failed = currentEntries.current.some(
                (entry) => entry.sending && entry.status !== 'done',
            );
            // Finalize even after failures: a 422 only means files are missing, a 403 or 419 that this upload can't go on.
            const result = await request<{ open: boolean; closes_at: string }>(
                finalize.url(transfer),
                'POST',
                undefined,
                controller.signal,
            ).catch((cause: unknown) => {
                if (failed && (cause as { status?: number }).status === 422)
                    return null;
                throw cause;
            });
            if (controller.signal.aborted) return;
            if (!result) {
                setError(SOME_FAILED);
                return;
            }
            showSent(result);
        } catch (cause) {
            if (controller.signal.aborted) return;
            // The Drop closed while the form was open: the reload shows the closed screen.
            if (
                !submission.current &&
                (cause as { status?: number }).status === 410
            )
                router.reload();
            else fail(cause);
        } finally {
            if (!cancelling.current) {
                operating.current = false;
                setBusy(false);
            }
            setRemaining(null);
        }
    }
    function showSent(result: { open: boolean; closes_at: string }) {
        submission.current = null;
        focusHeading.current = true;
        setSent({
            files: currentEntries.current.filter((entry) => entry.sending),
            time: clockTime(),
            open: result.open,
            closesAt: result.closes_at,
        });
    }
    async function cancelUpload() {
        if (cancelling.current || removing) return;
        if (
            !window.confirm(
                'Cancel this upload? Nothing will be sent, and the files uploaded so far will be discarded.',
            )
        )
            return;
        cancelling.current = true;
        operating.current = true;
        abort.current?.abort();
        setBusy(true);
        try {
            await upload.current;
            if (submission.current)
                await request(cancel.url(submission.current), 'DELETE');
            backToForm();
        } catch (cause) {
            // The files reached the owner before the cancel did: show what was sent.
            if (
                (cause as { status?: number }).status === 409 &&
                submission.current
            )
                await request<{ open: boolean; closes_at: string }>(
                    finalize.url(submission.current),
                    'POST',
                ).then(showSent, fail);
            else fail(cause);
        } finally {
            cancelling.current = false;
            operating.current = false;
            setBusy(false);
        }
    }
    async function removeFile(entry: Entry) {
        if (operating.current) return;
        const transfer = submission.current;
        const rows = transfer
            ? currentEntries.current.filter((item) => item.sending)
            : currentEntries.current;
        refocusRow.current = rows.findIndex((item) => item.key === entry.key);
        const others = currentEntries.current.filter(
            (item) => item.key !== entry.key,
        );
        if (!transfer) {
            changeEntries(others);
            return;
        }
        operating.current = true;
        setRemoving(true);
        setError('');
        try {
            // Removing the last file leaves nothing to send, so the submission is cancelled instead.
            if (rows.length === 1)
                await request(cancel.url(transfer), 'DELETE');
            else if (entry.remote)
                await request(
                    remove.url({ transfer, file: entry.remote.id }),
                    'DELETE',
                );
            changeEntries(others);
            if (rows.length === 1) backToForm();
        } catch (cause) {
            fail(cause);
        } finally {
            operating.current = false;
            setRemoving(false);
        }
    }
    function sendMore() {
        changeEntries([]);
        setMessage('');
        setTried(false);
        setAllFiles(false);
        setSent(null);
        focusHeading.current = true;
    }
    const avatar = owner && (
        <Avatar
            name={owner.name}
            url={owner.avatar_url}
            className="border-transparent bg-muted font-semibold text-foreground md:size-9 md:text-sm"
        />
    );
    const chooseFiles = (
        <>
            <span className="md:hidden">Choose files</span>
            <span className="hidden md:inline">Browse files</span>
        </>
    );
    return (
        <main
            className={cn(
                'flex flex-1 justify-center px-5 py-6 md:px-10',
                screen === 'form' ? 'md:py-9' : 'md:items-center md:pb-2',
            )}
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
            {sent ? (
                <section
                    key="sent"
                    className="flex w-full max-w-xl min-w-0 flex-col gap-6 transition-[opacity,translate] duration-300 ease-out md:gap-7 md:rounded-xl md:border md:bg-background md:p-10 starting:opacity-0 motion-safe:starting:translate-y-2"
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
                            ref={screenHeading}
                            tabIndex={-1}
                            className="text-4xl"
                        >
                            Files sent
                        </h1>
                        <p className="text-base text-muted-foreground">
                            {owner?.name ?? 'The recipient'} can download them
                            now.
                        </p>
                    </div>
                    <div>
                        <div className="mb-2 flex flex-wrap items-baseline justify-between gap-3">
                            <h2 className="font-sans text-sm font-semibold tracking-normal">
                                You sent
                            </h2>
                            <span className="text-sm text-muted-foreground">
                                {sent.files.length}{' '}
                                {sent.files.length === 1 ? 'file' : 'files'} ·{' '}
                                {bytes(
                                    sent.files.reduce(
                                        (sum, entry) => sum + entry.file.size,
                                        0,
                                    ),
                                )}{' '}
                                · today, {sent.time}
                            </span>
                        </div>
                        <ul className="border-y border-border">
                            {sent.files
                                .slice(0, allFiles ? undefined : 4)
                                .map((entry) => (
                                    <li key={entry.key}>
                                        <FileRow
                                            name={entry.file.name}
                                            size={entry.file.size}
                                        />
                                    </li>
                                ))}
                        </ul>
                        {sent.files.length > 4 && (
                            <Button
                                className="mt-3 h-auto min-h-0 p-0 text-sm"
                                variant="link"
                                onClick={() => setAllFiles(!allFiles)}
                            >
                                {allFiles
                                    ? 'Show fewer files'
                                    : `Show all ${sent.files.length} files`}
                            </Button>
                        )}
                    </div>
                    <p className="rounded-lg bg-muted px-4 py-3 text-sm text-slate-700">
                        This link can’t open these files, not even for you. Only{' '}
                        {recipient} can.
                    </p>
                    {sent.open && (
                        <div className="flex flex-col items-center gap-3 text-center">
                            <p className="text-sm text-muted-foreground">
                                Forgot something? The same link takes more files
                                until {dateTime(sent.closesAt)}.
                            </p>
                            <Button variant="outline" onClick={sendMore}>
                                Send more files
                            </Button>
                        </div>
                    )}
                </section>
            ) : screen === 'uploading' ? (
                <section className="flex w-full max-w-xl min-w-0 flex-col gap-6 md:gap-7 md:rounded-xl md:border md:bg-background md:p-10">
                    <div className="flex items-center gap-2.5 text-sm leading-snug text-muted-foreground">
                        {avatar}
                        <span className="min-w-0 wrap-anywhere">
                            <strong className="text-foreground">
                                {drop.title}
                            </strong>
                            <span className="block">
                                {owner
                                    ? `For ${owner.name} · Mediamax Communication`
                                    : 'Mediamax Communication'}
                            </span>
                        </span>
                    </div>
                    <div className="flex flex-col gap-3">
                        <div className="flex items-baseline justify-between gap-3">
                            <h1
                                ref={screenHeading}
                                tabIndex={-1}
                                className="text-3xl"
                            >
                                {busy
                                    ? 'Uploading…'
                                    : sending.some(
                                            (entry) =>
                                                entry.status === 'failed',
                                        )
                                      ? 'Upload incomplete'
                                      : 'Ready to send'}
                            </h1>
                            <strong className="font-heading text-3xl text-primary">
                                {percentage}%
                            </strong>
                        </div>
                        <progress
                            aria-label="Overall upload progress"
                            value={percentage}
                            max={100}
                        />
                        <div className="flex justify-between gap-2 text-sm text-muted-foreground">
                            <span>
                                {bytes(loaded)} of {bytes(totalSize)}
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
                    </div>
                    <ul ref={fileList} className="flex flex-col gap-2">
                        {sending.map((entry) => (
                            <li
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
                                                              entry.file.size) *
                                                              100,
                                                      ),
                                                  )
                                                : 0}
                                            %
                                        </span>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">
                                            Waiting
                                        </span>
                                    )}
                                    {!busy && entry.status === 'failed' && (
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Remove ${entry.file.name}`}
                                            disabled={removing}
                                            onClick={() =>
                                                void removeFile(entry)
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
                                        max={Math.max(entry.file.size, 1)}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="text-sm wrap-anywhere text-muted-foreground">
                        Sending as {name.trim()}
                        {message.trim() && ', with your message'}.
                    </p>
                    <div className="flex flex-col gap-3">
                        <ErrorMessage>{error}</ErrorMessage>
                        {!busy && (
                            <Button
                                size="lg"
                                disabled={removing}
                                onClick={(event) => {
                                    if (operating.current) return;
                                    screenHeading.current?.focus();
                                    start(event.timeStamp);
                                }}
                            >
                                Retry and send
                            </Button>
                        )}
                        <Button
                            variant="outline"
                            size="lg"
                            disabled={removing}
                            onClick={() => void cancelUpload()}
                        >
                            Cancel upload
                        </Button>
                        {busy && (
                            <p className="text-center text-sm text-muted-foreground">
                                <span className="md:hidden">
                                    Keep this page open and your screen on until
                                    the upload finishes.
                                </span>
                                <span className="hidden md:inline">
                                    Keep this tab open until the upload
                                    finishes.
                                </span>
                            </p>
                        )}
                    </div>
                </section>
            ) : (
                <form
                    noValidate
                    className="flex w-full max-w-xl min-w-0 flex-col gap-6 self-start md:gap-7 md:rounded-xl md:border md:bg-background md:p-10"
                    onSubmit={send}
                >
                    <div className="flex flex-col gap-3 md:gap-3.5">
                        {owner && (
                            <div className="flex items-center gap-2.5 text-sm leading-snug text-muted-foreground">
                                {avatar}
                                <span>
                                    <strong className="text-foreground">
                                        {owner.name}
                                    </strong>{' '}
                                    is asking for files
                                    <span className="block">
                                        Mediamax Communication
                                    </span>
                                </span>
                            </div>
                        )}
                        <h1
                            ref={screenHeading}
                            tabIndex={-1}
                            className="text-3xl leading-tight text-pretty wrap-anywhere"
                        >
                            {drop.title}
                        </h1>
                        {drop.instructions && (
                            <p className="text-base wrap-anywhere whitespace-pre-wrap text-muted-foreground">
                                {drop.instructions}
                            </p>
                        )}
                    </div>
                    <ul
                        aria-label="What this Drop accepts"
                        className="flex flex-col gap-2.5 rounded-lg bg-muted px-4 py-3 text-sm text-slate-700"
                    >
                        <li className="flex items-start gap-2.5">
                            <HugeiconsIcon
                                icon={Clock01Icon}
                                size={16}
                                className="mt-0.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>Open until {dateTime(drop.closes_at)}</span>
                        </li>
                        {types && (
                            <li className="flex items-start gap-2.5">
                                <HugeiconsIcon
                                    icon={
                                        drop.file_types.some(
                                            (type) => type !== 'documents',
                                        )
                                            ? Image01Icon
                                            : File01Icon
                                    }
                                    size={16}
                                    className="mt-0.5 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>
                                    {types} only
                                    <span className="block text-xs text-muted-foreground">
                                        {acceptedList(drop).join(', ')}
                                    </span>
                                </span>
                            </li>
                        )}
                        <li className="flex items-start gap-2.5">
                            <HugeiconsIcon
                                icon={Upload01Icon}
                                size={16}
                                className="mt-0.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                Up to {bytes(drop.max_file_size)} per file
                                {drop.max_submission_size !== null &&
                                    `, ${bytes(drop.max_submission_size)} in total`}
                            </span>
                        </li>
                    </ul>
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
                        <div className="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed border-input px-5 py-8 text-center">
                            <span className="inline-flex size-14 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <HugeiconsIcon
                                    icon={Upload01Icon}
                                    size={26}
                                    strokeWidth={2}
                                    aria-hidden="true"
                                />
                            </span>
                            <h2 className="text-xl">
                                <span className="md:hidden">
                                    Choose the files to send
                                </span>
                                <span className="hidden md:inline">
                                    Drop files here
                                </span>
                            </h2>
                            <Button
                                ref={browseFiles}
                                variant="outline"
                                aria-describedby="send-hint"
                                onClick={() => picker.current?.click()}
                            >
                                {chooseFiles}
                            </Button>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-3">
                            <div className="flex flex-wrap items-center gap-3 rounded-lg border-2 border-dashed border-input p-4">
                                <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <HugeiconsIcon
                                        icon={Upload01Icon}
                                        size={20}
                                        aria-hidden="true"
                                    />
                                </span>
                                <span className="min-w-35 flex-1 text-sm text-muted-foreground">
                                    <span className="md:hidden">
                                        Add more files
                                    </span>
                                    <span className="hidden md:inline">
                                        Drop more files here
                                    </span>
                                </span>
                                <Button
                                    ref={browseFiles}
                                    variant="outline"
                                    onClick={() => picker.current?.click()}
                                >
                                    {chooseFiles}
                                </Button>
                            </div>
                            <div className="flex flex-wrap items-baseline justify-between gap-3">
                                <h2 className="font-sans text-sm font-semibold tracking-normal">
                                    Files
                                </h2>
                                <span className="text-sm text-muted-foreground tabular-nums">
                                    {accepted.length}{' '}
                                    {accepted.length === 1 ? 'file' : 'files'} ·{' '}
                                    {bytes(acceptedSize)}
                                    {drop.max_submission_size !== null &&
                                        ` of ${bytes(drop.max_submission_size)}`}
                                </span>
                            </div>
                            <ul ref={fileList} className="flex flex-col gap-2">
                                {entries.map((entry, index) => (
                                    <li
                                        key={entry.key}
                                        className={cn(
                                            'overflow-hidden rounded-lg border bg-background px-3',
                                            reasons[index] && 'border-red-200',
                                        )}
                                    >
                                        <FileRow
                                            name={entry.file.name}
                                            size={entry.file.size}
                                            variant="upload"
                                        >
                                            {reasons[index] && (
                                                <span className="hidden text-sm text-destructive md:inline">
                                                    {reasons[index]}
                                                </span>
                                            )}
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                aria-label={`Remove ${entry.file.name}`}
                                                onClick={() =>
                                                    void removeFile(entry)
                                                }
                                            >
                                                <HugeiconsIcon
                                                    icon={Cancel01Icon}
                                                    size={16}
                                                    aria-hidden="true"
                                                />
                                            </Button>
                                        </FileRow>
                                        {/* Phones have no room beside the name, so the reason gets its own line. */}
                                        {reasons[index] && (
                                            <p className="pb-3 text-sm text-destructive md:hidden">
                                                {reasons[index]}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {rejected > 0 && (
                                <ErrorMessage>
                                    {rejected === 1
                                        ? '1 file won’t be sent because it doesn’t fit this Drop’s rules.'
                                        : `${rejected} files won’t be sent because they don’t fit this Drop’s rules.`}
                                </ErrorMessage>
                            )}
                        </div>
                    )}
                    <div className="flex flex-col gap-2">
                        <label
                            className="text-sm font-semibold"
                            htmlFor="uploader-name"
                        >
                            Your name
                        </label>
                        <input
                            ref={nameInput}
                            id="uploader-name"
                            autoComplete="name"
                            maxLength={120}
                            required
                            aria-invalid={nameError}
                            aria-describedby="name-note"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                        />
                        {nameError ? (
                            <ErrorMessage id="name-note">
                                Enter your name so {recipient} knows who sent
                                the files.
                            </ErrorMessage>
                        ) : (
                            <p
                                id="name-note"
                                className="text-sm text-muted-foreground"
                            >
                                So {recipient} knows who sent the files.
                            </p>
                        )}
                    </div>
                    <div className="flex flex-col gap-2">
                        <label
                            className="text-sm font-semibold"
                            htmlFor="uploader-message"
                        >
                            Message{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </label>
                        <textarea
                            id="uploader-message"
                            rows={3}
                            maxLength={2000}
                            placeholder={`Anything ${recipient} should know`}
                            value={message}
                            onChange={(event) => setMessage(event.target.value)}
                        />
                    </div>
                    <div className="flex flex-col gap-3">
                        <ErrorMessage>{error}</ErrorMessage>
                        <Button
                            size="lg"
                            type="submit"
                            aria-describedby={
                                accepted.length ? undefined : 'send-hint'
                            }
                        >
                            {accepted.length
                                ? `Send ${accepted.length} ${accepted.length === 1 ? 'file' : 'files'} · ${bytes(acceptedSize)}`
                                : 'Send files'}
                        </Button>
                        {!accepted.length && (
                            <p
                                id="send-hint"
                                className={cn(
                                    'text-center text-sm text-muted-foreground',
                                    tried && 'text-destructive',
                                )}
                            >
                                Add at least one file to continue
                            </p>
                        )}
                        <p className="text-center text-sm text-muted-foreground">
                            Only {owner?.name ?? 'the recipient'} sees what you
                            send. This link can’t open or list any files.
                        </p>
                    </div>
                </form>
            )}
            {dragging && screen === 'form' && (
                <div className="pointer-events-none fixed inset-3 z-50 flex items-center justify-center rounded-xl border-2 border-dashed border-primary bg-accent/95 p-6 text-center font-heading text-4xl transition-opacity duration-150 ease-out starting:opacity-0">
                    Drop to add your files
                </div>
            )}
        </main>
    );
}
