import {
    ArrowLeft01Icon,
    ArrowRight01Icon,
    File01Icon,
    Image01Icon,
    Tick02Icon,
    Upload01Icon,
    Video01Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link } from '@inertiajs/react';
import {
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
    type FormEvent,
} from 'react';
import { flushSync } from 'react-dom';
import { CopyLink, ErrorMessage, RadioPill, Shell } from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    acceptedList,
    fileSizeLimit,
    MAX_FILE_SIZE,
    rulesSummary,
    typesLabel,
} from '@/lib/drop';
import { bytes, dateTime } from '@/lib/format';
import { request } from '@/lib/http';
import type { Drop, DropFileType, DropRules } from '@/lib/types';
import { cn } from '@/lib/utils';
import { create, index, store } from '@/routes/drops';
import { index as received } from '@/routes/received';

const acceptOptions = [1, 7, 14, 30] as const;
const keepOptions = [7, 14, 30] as const;
const presets = [
    { type: 'documents', label: 'Documents', icon: File01Icon },
    { type: 'images', label: 'Images', icon: Image01Icon },
    { type: 'videos', label: 'Videos', icon: Video01Icon },
] as const;

type Unit = 'MB' | 'GB';

const subscribeNothing = () => () => {};

function toBytes(value: string, unit: Unit): number | null {
    return value.trim() === ''
        ? null
        : Math.round(parseFloat(value) * (unit === 'GB' ? 1e9 : 1e6));
}

export default function CreateDrop() {
    const [title, setTitle] = useState('');
    const [instructions, setInstructions] = useState('');
    const [acceptDays, setAcceptDays] =
        useState<(typeof acceptOptions)[number]>(7);
    const [keepDays, setKeepDays] = useState<(typeof keepOptions)[number]>(30);
    const [restrictionsOpen, setRestrictionsOpen] = useState(false);
    const [maxFile, setMaxFile] = useState('');
    const [maxFileUnit, setMaxFileUnit] = useState<Unit>('GB');
    const [maxTotal, setMaxTotal] = useState('');
    const [maxTotalUnit, setMaxTotalUnit] = useState<Unit>('GB');
    const [fileTypes, setFileTypes] = useState<DropFileType[]>([]);
    const [custom, setCustom] = useState('');
    const [tried, setTried] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    // False while server rendering and hydrating, so the local date and the submit button wait for the browser.
    const mounted = useSyncExternalStore(
        subscribeNothing,
        () => true,
        () => false,
    );
    const [openedAt] = useState(() => Date.now());
    const [ready, setReady] = useState<Drop | null>(null);
    const readyHeading = useRef<HTMLHeadingElement>(null);
    const titleInput = useRef<HTMLInputElement>(null);
    const maxFileInput = useRef<HTMLInputElement>(null);
    const maxTotalInput = useRef<HTMLInputElement>(null);
    const customInput = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (ready && document.activeElement === document.body)
            readyHeading.current?.focus();
    }, [ready]);

    const perFile = toBytes(maxFile, maxFileUnit);
    const total = toBytes(maxTotal, maxTotalUnit);
    const fileError =
        perFile === null
            ? ''
            : !(perFile > 0)
              ? 'Enter a size above 0.'
              : perFile > MAX_FILE_SIZE
                ? 'Filemax accepts files up to 100 GB. Enter 100 GB or less.'
                : '';
    const totalError =
        total === null
            ? ''
            : !(total > 0)
              ? 'Enter a size above 0.'
              : perFile !== null && !fileError && total < perFile
                ? `Make it at least the size per file, ${bytes(perFile)}.`
                : '';
    const rules: DropRules = {
        max_file_size: fileError ? null : perFile,
        max_submission_size: totalError ? null : total,
        file_types: fileTypes,
        extensions: [
            ...new Set(
                custom
                    .split(/[\s,]+/)
                    .map((extension) =>
                        extension.replace(/^\.+/, '').toLowerCase(),
                    )
                    .filter(Boolean),
            ),
        ],
    };
    // Same rule as the server, so a bad extension is caught beside its field.
    const extensionsError = rules.extensions.some(
        (extension) => !/^[a-z0-9]{1,16}$/.test(extension),
    )
        ? 'Use letters and numbers only for extensions, like .indd or .zip.'
        : rules.extensions.length > 20
          ? 'Add up to 20 extensions.'
          : '';
    const accepted = acceptedList(rules);
    const titleMissing = tried && !title.trim();

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (busy) return;
        setTried(true);
        if (!title.trim()) {
            titleInput.current?.focus();
            return;
        }
        if (fileError || totalError || extensionsError) {
            flushSync(() => setRestrictionsOpen(true));
            (fileError
                ? maxFileInput
                : totalError
                  ? maxTotalInput
                  : customInput
            ).current?.focus();
            return;
        }
        setBusy(true);
        setError('');
        try {
            const result = await request<{ drop: Drop }>(store.url(), 'POST', {
                title: title.trim(),
                instructions: instructions.trim() || null,
                accept_days: acceptDays,
                keep_days: keepDays,
                ...rules,
            });
            setReady(result.drop);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'This Drop could not be created. Please try again.',
            );
        } finally {
            setBusy(false);
        }
    }

    if (ready)
        return (
            <Shell active="new-drop">
                <Head title="Your Drop is ready" />
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
                                Your Drop is ready
                            </h1>
                            <p className="wrap-anywhere text-muted-foreground">
                                {ready.title} · open until{' '}
                                {dateTime(ready.closes_at)}
                            </p>
                        </div>
                        <CopyLink url={ready.url} />
                        <div className="flex flex-col gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-slate-700">
                            <strong className="inline-flex items-center gap-2">
                                <HugeiconsIcon
                                    icon={Upload01Icon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                Upload only
                            </strong>
                            <p className="text-sm">
                                Anyone with this link can send you files until
                                the deadline. They can’t see your transfers, or
                                what anyone else sent.
                            </p>
                        </div>
                        <dl className="border-t text-sm">
                            {[
                                [
                                    'Accepts',
                                    typesLabel(ready) ?? 'Any file type',
                                ],
                                [
                                    'Size per file',
                                    `Up to ${bytes(fileSizeLimit(ready))}`,
                                ],
                                [
                                    'Size per submission',
                                    ready.max_submission_size
                                        ? `Up to ${bytes(ready.max_submission_size)}`
                                        : 'No limit',
                                ],
                                [
                                    'Received files kept',
                                    `${ready.keep_days} days after each upload`,
                                ],
                            ].map(([term, value]) => (
                                <div
                                    key={term}
                                    className="flex min-h-11 items-center justify-between gap-3 border-b py-2.5"
                                >
                                    <dt className="text-muted-foreground">
                                        {term}
                                    </dt>
                                    <dd className="text-right font-medium wrap-anywhere">
                                        {value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        <p className="text-sm text-muted-foreground">
                            Each upload arrives in{' '}
                            <Link href={received()} className="font-semibold">
                                My transfers › Received
                            </Link>
                            , and you get an email.
                        </p>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Link href={index()} className="font-semibold">
                                View Drops
                            </Link>
                            <Link
                                href={create()}
                                className={cn(
                                    buttonVariants({ variant: 'outline' }),
                                )}
                            >
                                Create another
                            </Link>
                        </div>
                    </section>
                </main>
            </Shell>
        );

    return (
        <Shell active="new-drop">
            <Head title="New Drop" />
            <main className="flex flex-1 justify-center bg-muted px-5 py-6 md:p-10">
                <div className="flex w-full max-w-2xl min-w-0 flex-col gap-6">
                    <Link
                        href={index()}
                        className="inline-flex w-fit items-center gap-1.5 text-sm font-semibold"
                    >
                        <HugeiconsIcon
                            icon={ArrowLeft01Icon}
                            size={16}
                            aria-hidden="true"
                        />
                        Drops
                    </Link>
                    <form
                        noValidate
                        className="flex flex-col gap-6 rounded-xl border bg-background px-5 py-7 md:p-10"
                        onSubmit={submit}
                    >
                        <div className="flex flex-col gap-2">
                            <h1>New Drop</h1>
                            <p className="text-base text-muted-foreground">
                                Ask anyone for files, no account needed. The
                                link only lets them upload: they never see your
                                transfers or what anyone else sent.
                            </p>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                className="text-sm font-semibold"
                                htmlFor="drop-title"
                            >
                                Title
                            </label>
                            <input
                                ref={titleInput}
                                id="drop-title"
                                required
                                maxLength={255}
                                placeholder="What do you need?"
                                aria-invalid={titleMissing}
                                aria-describedby="title-note"
                                value={title}
                                onChange={(event) =>
                                    setTitle(event.target.value)
                                }
                                disabled={busy}
                            />
                            {titleMissing ? (
                                <ErrorMessage id="title-note">
                                    Add a title so people know what to send.
                                </ErrorMessage>
                            ) : (
                                <p
                                    id="title-note"
                                    className="text-sm text-muted-foreground"
                                >
                                    The heading of the upload page.
                                </p>
                            )}
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                className="text-sm font-semibold"
                                htmlFor="drop-instructions"
                            >
                                Instructions{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional)
                                </span>
                            </label>
                            <textarea
                                id="drop-instructions"
                                rows={3}
                                maxLength={2000}
                                placeholder="What to send, and how to name it"
                                aria-describedby="instructions-note"
                                value={instructions}
                                onChange={(event) =>
                                    setInstructions(event.target.value)
                                }
                                disabled={busy}
                            />
                            <p
                                id="instructions-note"
                                className="text-sm text-muted-foreground"
                            >
                                Shown above the upload area.
                            </p>
                        </div>
                        <fieldset>
                            <legend className="mb-2.5 text-sm font-semibold">
                                Accept uploads for
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {acceptOptions.map((days) => (
                                    <RadioPill
                                        key={days}
                                        name="accept"
                                        checked={acceptDays === days}
                                        onChange={() => setAcceptDays(days)}
                                        disabled={busy}
                                    >
                                        {days} {days === 1 ? 'day' : 'days'}
                                    </RadioPill>
                                ))}
                            </div>
                            <p
                                className="mt-2.5 text-sm text-muted-foreground"
                                role="status"
                            >
                                {mounted &&
                                    `Until ${dateTime(new Date(openedAt + acceptDays * 86_400_000).toISOString())}. `}
                                You can close it sooner from Drops.
                            </p>
                        </fieldset>
                        <fieldset>
                            <legend className="mb-2.5 text-sm font-semibold">
                                Keep received files for
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {keepOptions.map((days) => (
                                    <RadioPill
                                        key={days}
                                        name="keep"
                                        checked={keepDays === days}
                                        onChange={() => setKeepDays(days)}
                                        disabled={busy}
                                    >
                                        {days} days
                                    </RadioPill>
                                ))}
                            </div>
                            <p className="mt-2.5 text-sm text-muted-foreground">
                                Counted from each upload. Closing the Drop, or
                                reaching its deadline, never deletes what you’ve
                                received.
                            </p>
                        </fieldset>
                        <section className="rounded-lg border">
                            <button
                                type="button"
                                className="flex w-full items-center justify-between gap-3 rounded-lg p-4 text-left hover:bg-muted/50"
                                aria-expanded={restrictionsOpen}
                                aria-controls="upload-restrictions"
                                onClick={() =>
                                    setRestrictionsOpen(!restrictionsOpen)
                                }
                            >
                                <span className="flex min-w-0 flex-col gap-0.5">
                                    <span className="text-sm font-semibold">
                                        Upload restrictions{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (optional)
                                        </span>
                                    </span>
                                    <span className="text-sm wrap-anywhere text-muted-foreground">
                                        {rulesSummary(rules, true)}
                                    </span>
                                </span>
                                <HugeiconsIcon
                                    icon={ArrowRight01Icon}
                                    size={18}
                                    aria-hidden="true"
                                    className={cn(
                                        'shrink-0 text-slate-600 transition-transform duration-160 ease-out motion-reduce:transition-none',
                                        restrictionsOpen && 'rotate-90',
                                    )}
                                />
                            </button>
                            <div
                                id="upload-restrictions"
                                hidden={!restrictionsOpen}
                                className="flex flex-col gap-6 border-t p-5"
                            >
                                <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                                    <div className="flex flex-col gap-2">
                                        <label
                                            className="text-sm font-semibold"
                                            htmlFor="max-file"
                                        >
                                            Max size per file
                                        </label>
                                        <div className="flex gap-2">
                                            <input
                                                ref={maxFileInput}
                                                id="max-file"
                                                type="number"
                                                min="0"
                                                step="any"
                                                inputMode="decimal"
                                                className="flex-1"
                                                aria-invalid={!!fileError}
                                                aria-describedby="max-file-note"
                                                value={maxFile}
                                                onChange={(event) =>
                                                    setMaxFile(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={busy}
                                            />
                                            <select
                                                aria-label="Unit for max size per file"
                                                className="w-21 shrink-0"
                                                value={maxFileUnit}
                                                onChange={(event) =>
                                                    setMaxFileUnit(
                                                        event.target
                                                            .value as Unit,
                                                    )
                                                }
                                                disabled={busy}
                                            >
                                                <option value="MB">MB</option>
                                                <option value="GB">GB</option>
                                            </select>
                                        </div>
                                        {fileError ? (
                                            <ErrorMessage id="max-file-note">
                                                {fileError}
                                            </ErrorMessage>
                                        ) : (
                                            <p
                                                id="max-file-note"
                                                className="text-sm text-muted-foreground"
                                            >
                                                Up to 100 GB per file.
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <label
                                            className="text-sm font-semibold"
                                            htmlFor="max-total"
                                        >
                                            Max total per submission
                                        </label>
                                        <div className="flex gap-2">
                                            <input
                                                ref={maxTotalInput}
                                                id="max-total"
                                                type="number"
                                                min="0"
                                                step="any"
                                                inputMode="decimal"
                                                className="flex-1"
                                                aria-invalid={!!totalError}
                                                aria-describedby="max-total-note"
                                                value={maxTotal}
                                                onChange={(event) =>
                                                    setMaxTotal(
                                                        event.target.value,
                                                    )
                                                }
                                                disabled={busy}
                                            />
                                            <select
                                                aria-label="Unit for max total per submission"
                                                className="w-21 shrink-0"
                                                value={maxTotalUnit}
                                                onChange={(event) =>
                                                    setMaxTotalUnit(
                                                        event.target
                                                            .value as Unit,
                                                    )
                                                }
                                                disabled={busy}
                                            >
                                                <option value="MB">MB</option>
                                                <option value="GB">GB</option>
                                            </select>
                                        </div>
                                        {totalError ? (
                                            <ErrorMessage id="max-total-note">
                                                {totalError}
                                            </ErrorMessage>
                                        ) : (
                                            <p
                                                id="max-total-note"
                                                className="text-sm text-muted-foreground"
                                            >
                                                Everything someone sends at
                                                once, so many files can’t add up
                                                past your limit.
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <fieldset aria-describedby="types-note">
                                    <legend className="mb-2.5 text-sm font-semibold">
                                        Allowed file types
                                    </legend>
                                    <div className="flex flex-wrap gap-2">
                                        {presets.map((preset) => (
                                            <label
                                                key={preset.type}
                                                className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-full border border-input px-3 has-checked:border-primary has-checked:bg-primary/5 has-checked:font-semibold has-checked:text-primary has-checked:ring-1 has-checked:ring-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="sr-only"
                                                    checked={fileTypes.includes(
                                                        preset.type,
                                                    )}
                                                    onChange={(event) =>
                                                        setFileTypes(
                                                            event.target.checked
                                                                ? [
                                                                      ...fileTypes,
                                                                      preset.type,
                                                                  ]
                                                                : fileTypes.filter(
                                                                      (type) =>
                                                                          type !==
                                                                          preset.type,
                                                                  ),
                                                        )
                                                    }
                                                    disabled={busy}
                                                />
                                                <HugeiconsIcon
                                                    icon={preset.icon}
                                                    size={16}
                                                    aria-hidden="true"
                                                />
                                                {preset.label}
                                            </label>
                                        ))}
                                    </div>
                                    <p
                                        id="types-note"
                                        className="mt-2.5 text-sm wrap-anywhere text-muted-foreground"
                                    >
                                        {accepted.length
                                            ? `Accepts ${accepted.join(', ')}.`
                                            : 'Any file type is accepted. Pick a preset or add extensions to narrow it.'}
                                    </p>
                                </fieldset>
                                <div className="flex flex-col gap-2">
                                    <label
                                        className="text-sm font-semibold"
                                        htmlFor="custom-types"
                                    >
                                        Other extensions
                                    </label>
                                    <input
                                        ref={customInput}
                                        id="custom-types"
                                        placeholder=".indd, .zip"
                                        autoComplete="off"
                                        spellCheck={false}
                                        aria-describedby="custom-note"
                                        aria-invalid={!!extensionsError}
                                        value={custom}
                                        onChange={(event) =>
                                            setCustom(event.target.value)
                                        }
                                        disabled={busy}
                                    />
                                    {extensionsError ? (
                                        <ErrorMessage id="custom-note">
                                            {extensionsError}
                                        </ErrorMessage>
                                    ) : (
                                        <p
                                            id="custom-note"
                                            className="text-sm text-muted-foreground"
                                        >
                                            Separate them with commas. They’re
                                            added to the types you picked.
                                        </p>
                                    )}
                                </div>
                            </div>
                        </section>
                        <div className="flex flex-col gap-3">
                            <ErrorMessage>{error}</ErrorMessage>
                            <Button
                                size="lg"
                                type="submit"
                                disabled={!mounted || busy}
                                aria-describedby="submit-hint"
                            >
                                {busy ? 'Creating…' : 'Create Drop'}
                            </Button>
                            <p
                                id="submit-hint"
                                className="text-center text-sm text-muted-foreground"
                            >
                                You’ll copy the link on the next screen. Uploads
                                arrive in My transfers › Received.
                            </p>
                        </div>
                    </form>
                </div>
            </main>
        </Shell>
    );
}
