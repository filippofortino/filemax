import {
    Alert02Icon,
    Cancel01Icon,
    Globe02Icon,
    Tick02Icon,
    Upload01Icon,
    UserGroupIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type DragEvent } from 'react';
import {
    CopyLink,
    ErrorMessage,
    FileRow,
    Shell,
    TeamBadges,
    TeamPicker,
} from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { bytes, date } from '@/lib/format';
import { request, uploadPart } from '@/lib/http';
import type { SharedProps, Team, Transfer, TransferFile } from '@/lib/types';
import { home } from '@/routes';
import { destroy, show, store, update } from '@/routes/transfers';
import { complete, finalize, remove, sign } from '@/routes/transfers/uploads';

type Entry = {
    key: string;
    file: File;
    remote?: TransferFile;
    status: 'waiting' | 'uploading' | 'done' | 'failed';
    loaded: number;
    error?: string;
};
export default function Create({ teams }: { teams: Team[] }) {
    const csrf = usePage<SharedProps>().props.csrf_token;
    const [entries, setEntries] = useState<Entry[]>([]);
    const currentEntries = useRef<Entry[]>([]);
    const draft = useRef<Transfer | null>(null);
    const abort = useRef<AbortController | null>(null);
    const operating = useRef(false);
    const cancelling = useRef(false);
    const picker = useRef<HTMLInputElement>(null);
    const [title, setTitle] = useState('');
    const [message, setMessage] = useState('');
    const [visibility, setVisibility] = useState<'public' | 'teams'>('public');
    const [selectedTeams, setSelectedTeams] = useState<string[]>([]);
    const [expiry, setExpiry] = useState(7);
    const [busy, setBusy] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [error, setError] = useState('');
    const [ready, setReady] = useState<Transfer | null>(null);
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
        setRemoving(true);
        setBusy(true);
        setError('');
        try {
            if (draft.current && entry.remote)
                await request(
                    remove.url({
                        transfer: draft.current.id,
                        file: entry.remote.id,
                    }),
                    csrf,
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
    async function send() {
        if (operating.current || !currentEntries.current.length) return;
        operating.current = true;
        setBusy(true);
        setError('');
        setRemaining(null);
        const controller = new AbortController();
        abort.current = controller;
        const started = Date.now();
        const initialLoaded = currentEntries.current.reduce(
            (sum, entry) => sum + entry.loaded,
            0,
        );
        try {
            if (!draft.current) {
                const result = await request<{ transfer: Transfer }>(
                    store.url(),
                    csrf,
                    'POST',
                    {
                        title,
                        message,
                        visibility,
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
                changeEntries(
                    currentEntries.current.map((entry, index) => ({
                        ...entry,
                        remote: result.transfer.files[index],
                    })),
                );
            }
            if (controller.signal.aborted) return;
            const transferId = draft.current.id;
            for (const entry of [...currentEntries.current]) {
                if (entry.status === 'done') continue;
                const remote = entry.remote!;
                patch(entry.key, { status: 'uploading', error: undefined });
                try {
                    const count = Math.max(
                        1,
                        Math.ceil(entry.file.size / remote.part_size),
                    );
                    for (let part = 1; part <= count; part++) {
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
                            csrf,
                            'POST',
                            undefined,
                            controller.signal,
                        );
                        const offset = (part - 1) * remote.part_size;
                        const blob = entry.file.slice(
                            offset,
                            Math.min(
                                offset + remote.part_size,
                                entry.file.size,
                            ),
                        );
                        if (!signed.completed) {
                            const headers = { ...signed.headers };
                            if (
                                new URL(signed.url, window.location.href)
                                    .origin === window.location.origin
                            )
                                headers['X-CSRF-TOKEN'] = csrf;
                            await uploadPart(
                                signed.url,
                                headers,
                                blob,
                                controller.signal,
                                (loaded) => {
                                    patch(entry.key, {
                                        loaded: offset + loaded,
                                    });
                                    const totalLoaded =
                                        currentEntries.current.reduce(
                                            (sum, item) => sum + item.loaded,
                                            0,
                                        );
                                    const transferred =
                                        totalLoaded - initialLoaded;
                                    const elapsed =
                                        (Date.now() - started) / 1000;
                                    if (elapsed > 2 && transferred > 0)
                                        setRemaining(
                                            Math.max(
                                                0,
                                                Math.round(
                                                    (totalSize - totalLoaded) /
                                                        (transferred / elapsed),
                                                ),
                                            ),
                                        );
                                },
                            );
                        }
                        patch(entry.key, { loaded: offset + blob.size });
                    }
                    await request(
                        complete.url({ transfer: transferId, file: remote.id }),
                        csrf,
                        'POST',
                        undefined,
                        controller.signal,
                    );
                    patch(entry.key, {
                        status: 'done',
                        loaded: entry.file.size,
                    });
                } catch (cause) {
                    if (controller.signal.aborted) return;
                    patch(entry.key, {
                        status: 'failed',
                        error: (cause as Error).message,
                    });
                }
            }
            if (controller.signal.aborted) return;
            if (
                currentEntries.current.some((entry) => entry.status !== 'done')
            ) {
                setError(
                    'Some files could not be uploaded. Completed files are safe — retry sends only what is missing.',
                );
                return;
            }
            if (visibility === 'teams')
                await request(
                    update.url(transferId),
                    csrf,
                    'PATCH',
                    { team_ids: selectedTeams },
                    controller.signal,
                );
            const result = await request<{ transfer: Transfer }>(
                finalize.url(transferId),
                csrf,
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
        cancelling.current = true;
        operating.current = true;
        abort.current?.abort();
        setBusy(true);
        try {
            if (draft.current)
                await request(destroy.url(draft.current.id), csrf, 'DELETE');
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
    const hasDraft = !!draft.current;
    if (ready)
        return (
            <Shell>
                <Head title="Your link is ready" />
                <main className="center-stage dotted">
                    <section className="ready-card">
                        <div className="flex flex-col items-center gap-3.5 text-center">
                            <span className="round-icon">
                                <HugeiconsIcon
                                    icon={Tick02Icon}
                                    size={30}
                                    aria-hidden="true"
                                />
                            </span>
                            <h1 className="text-4xl">Your link is ready</h1>
                            <p className="muted">
                                {ready.files.length} files ·{' '}
                                {bytes(ready.total_size)} · expires{' '}
                                {date(ready.expires_at)}
                            </p>
                        </div>
                        <CopyLink url={ready.url} />
                        {ready.visibility === 'teams' ? (
                            <div className="notice flex flex-col gap-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <strong>Shared with</strong>
                                    <TeamBadges teams={ready.teams} />
                                </div>
                                <p className="text-[13px]">
                                    Only signed-in, verified members of one of
                                    these teams can download.
                                </p>
                            </div>
                        ) : (
                            <p className="notice">
                                Anyone with the link. No account needed.
                            </p>
                        )}
                        <div>
                            {ready.files.map((file) => (
                                <FileRow
                                    key={file.id}
                                    name={file.original_name}
                                    size={file.size}
                                />
                            ))}
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <Link
                                href={show(ready.id)}
                                className="font-semibold"
                            >
                                View transfer
                            </Link>
                            <Button variant="outline" asChild>
                                <Link
                                    href={home()}
                                    onClick={() => {
                                        setReady(null);
                                        draft.current = null;
                                        changeEntries([]);
                                        setTitle('');
                                        setMessage('');
                                    }}
                                >
                                    Send another
                                </Link>
                            </Button>
                        </div>
                    </section>
                </main>
            </Shell>
        );
    return (
        <Shell>
            <Head title="New transfer" />
            <div
                className="upload-grid"
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
                <section className="upload-files dotted">
                    <input
                        ref={picker}
                        type="file"
                        multiple
                        className="sr-only"
                        aria-label="Choose files"
                        onChange={(event) => {
                            if (event.target.files)
                                addFiles(event.target.files);
                            event.target.value = '';
                        }}
                    />
                    {!entries.length ? (
                        <div className="drop-empty">
                            <span className="round-icon">
                                <HugeiconsIcon
                                    icon={Upload01Icon}
                                    size={32}
                                    strokeWidth={2}
                                    aria-hidden="true"
                                />
                            </span>
                            <h1>Drop files here</h1>
                            <p>Anywhere on this page works.</p>
                            <Button
                                variant="outline"
                                onClick={() => picker.current?.click()}
                            >
                                Browse files
                            </Button>
                        </div>
                    ) : (
                        <>
                            <div className="upload-progress">
                                <div className="flex items-baseline justify-between gap-3">
                                    <h1 className="text-[28px]">
                                        {busy
                                            ? 'Uploading…'
                                            : hasDraft
                                              ? entries.some(
                                                    (entry) =>
                                                        entry.status ===
                                                        'failed',
                                                )
                                                  ? 'A little interruption'
                                                  : 'Ready to finish'
                                              : 'Ready to send'}
                                    </h1>
                                    {hasDraft && (
                                        <strong className="font-heading text-[28px] text-primary">
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
                                        <div className="muted flex justify-between gap-2 text-[13px]">
                                            <span>
                                                {bytes(loaded)} of{' '}
                                                {bytes(totalSize)}
                                            </span>
                                            {remaining !== null && (
                                                <span>
                                                    About{' '}
                                                    {remaining < 60
                                                        ? `${remaining} seconds`
                                                        : `${Math.ceil(remaining / 60)} minutes`}{' '}
                                                    left
                                                </span>
                                            )}
                                        </div>
                                    </>
                                ) : (
                                    <p className="muted">
                                        {entries.length} files ·{' '}
                                        {bytes(totalSize)}
                                    </p>
                                )}
                            </div>
                            <div className="flex flex-col gap-2">
                                {entries.map((entry) => (
                                    <div
                                        className="upload-file"
                                        key={entry.key}
                                    >
                                        <FileRow
                                            name={entry.file.name}
                                            size={entry.file.size}
                                        >
                                            {entry.status === 'done' ? (
                                                <span className="flex items-center gap-1 text-[13px] font-semibold text-[#1a7f4b]">
                                                    <HugeiconsIcon
                                                        icon={Tick02Icon}
                                                        size={16}
                                                        aria-hidden="true"
                                                    />
                                                    Done
                                                </span>
                                            ) : entry.status === 'failed' ? (
                                                <span className="text-[13px] text-destructive">
                                                    Failed
                                                </span>
                                            ) : entry.status === 'uploading' ? (
                                                <span className="text-[13px] text-primary">
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
                                                <span className="muted text-[13px]">
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
                                            <p className="error-message pb-3">
                                                {entry.error}
                                            </p>
                                        )}
                                        {entry.status === 'uploading' && (
                                            <progress
                                                className="!h-[3px]"
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
                            {hasDraft && (
                                <p className="muted text-center text-[13px]">
                                    Keep this tab open until the upload
                                    finishes.
                                </p>
                            )}
                        </>
                    )}
                </section>
                <form
                    className="transfer-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void send();
                    }}
                >
                    <h2>Transfer details</h2>
                    {hasDraft ? (
                        <>
                            <div className="flex flex-col gap-2">
                                <span className="field-label">Title</span>
                                <div className="rounded-md border bg-muted px-3.5 py-3 text-[15px]">
                                    {title || entries[0]?.file.name}
                                </div>
                            </div>
                            <div className="flex flex-col gap-2">
                                <span className="field-label">Message</span>
                                <div className="rounded-md border bg-muted px-3.5 py-3 text-[15px] whitespace-pre-wrap">
                                    {message || 'No message'}
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="flex flex-col gap-2.5">
                                    <span className="field-label">
                                        Who can download
                                    </span>
                                    {visibility === 'teams' ? (
                                        !busy ? (
                                            <TeamPicker
                                                teams={teams}
                                                selected={selectedTeams}
                                                onChange={setSelectedTeams}
                                            />
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
                                        <span className="notice flex w-fit items-center gap-2">
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
                                    <span className="field-label">
                                        Link expires
                                    </span>
                                    <span className="rounded-md border bg-muted px-3.5 py-3 text-[15px]">
                                        {expiry} {expiry === 1 ? 'day' : 'days'}
                                    </span>
                                </div>
                            </div>
                        </>
                    ) : (
                        <>
                            <div className="field">
                                <label htmlFor="transfer-title">
                                    Title{' '}
                                    <span className="muted font-normal">
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
                            <div className="field">
                                <label htmlFor="transfer-message">
                                    Message{' '}
                                    <span className="muted font-normal">
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
                                <legend className="field-label mb-2.5">
                                    Who can download
                                </legend>
                                <div className="visibility-options">
                                    {(
                                        [
                                            {
                                                value: 'public',
                                                icon: Globe02Icon,
                                                label: 'Public link',
                                                text: 'Anyone with the link. No account needed.',
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
                                            className="visibility-option"
                                            key={option.value}
                                        >
                                            <span className="flex items-center justify-between">
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
                                                    }}
                                                />
                                            </span>
                                            <span>
                                                <strong>{option.label}</strong>
                                                <p>{option.text}</p>
                                            </span>
                                        </label>
                                    ))}
                                </div>
                                <p className="muted text-[13px]">
                                    {teams.length
                                        ? `You belong to ${teams.length} ${teams.length === 1 ? 'team' : 'teams'}: ${teams.map((team) => team.name).join(', ')}.`
                                        : 'No team memberships yet. You can share public links, or ask an admin to add you to a team.'}
                                </p>
                                {visibility === 'teams' && (
                                    <>
                                        <TeamPicker
                                            teams={teams}
                                            selected={selectedTeams}
                                            onChange={setSelectedTeams}
                                            disabled={busy}
                                        />
                                        {!selectedTeams.length && (
                                            <p className="muted text-[13px]">
                                                Select at least one team.
                                            </p>
                                        )}
                                    </>
                                )}
                            </fieldset>
                            <fieldset>
                                <legend className="field-label mb-2.5">
                                    Link expires
                                </legend>
                                <div className="expiry-options">
                                    {[1, 7, 14, 30].map((days) => (
                                        <label
                                            key={days}
                                            className="expiry-option"
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
                                <p className="muted mt-2.5 text-[13px]">
                                    Available until{' '}
                                    {date(availableUntil.toISOString())}.
                                </p>
                            </fieldset>
                        </>
                    )}
                    <div className="flex flex-col gap-3">
                        <ErrorMessage>{error}</ErrorMessage>
                        {error && hasDraft && (
                            <p className="notice text-[13px]">
                                <HugeiconsIcon
                                    className="mr-1 inline"
                                    icon={Alert02Icon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                Completed files are safe. Retry failed files or
                                remove them to finish with the rest.
                            </p>
                        )}
                        <Button
                            size="lg"
                            type="submit"
                            className={busy && hasDraft ? 'hidden' : undefined}
                            disabled={
                                busy ||
                                entries.length === 0 ||
                                (visibility === 'teams' &&
                                    selectedTeams.length === 0)
                            }
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
                        <p className="muted text-center text-[13px]">
                            {!entries.length
                                ? 'Add at least one file to continue'
                                : busy
                                  ? 'Your link appears as soon as the last file lands.'
                                  : 'Your files stay private until your link is ready.'}
                        </p>
                    </div>
                </form>
                {dragging && !hasDraft && (
                    <div className="drop-overlay">Drop to add your files</div>
                )}
            </div>
        </Shell>
    );
}
