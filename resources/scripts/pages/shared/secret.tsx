import {
    Clock01Icon,
    Copy01Icon,
    LockKeyIcon,
    Tick02Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Avatar } from '@/components/avatar';
import { ErrorMessage, Shell } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { date, dateTime } from '@/lib/format';
import { request } from '@/lib/http';
import { normaliseKey, secretKeys } from '@/lib/secret';
import { open as openSecret } from '@/routes/shared/secrets';

type SharedSecret = {
    token: string;
    sender: { name: string; avatar_url: string | null } | null;
    max_views: number;
    views_left: number;
    expires_at: string;
    last_opened_at: string | null;
    revoked_at: string | null;
    available: boolean;
    reason: 'opened' | 'expired' | 'deleted' | null;
};
type Opened = { text: string; viewsLeft: number; openedAt: string };
type KeyError = 'wrong' | 'throttled' | 'link';

export default function SharedSecretPage({
    secret,
}: {
    secret: SharedSecret | null;
}) {
    const [mode, setMode] = useState<'view' | 'key' | null>(null);
    const [linkKey, setLinkKey] = useState<string | null>(null);
    const [keyInput, setKeyInput] = useState('');
    const [keyError, setKeyError] = useState<KeyError | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [opened, setOpened] = useState<Opened | null>(null);
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState('');
    // Once a request is in flight or the secret is shown, no other open may replace it.
    const opening = useRef(false);
    const screenHeading = useRef<HTMLHeadingElement>(null);
    const focusHeading = useRef(false);

    useEffect(() => {
        if (!secret?.available || opened) return;
        const syncKey = () => {
            const hash = window.location.hash.slice(1);
            const key = normaliseKey(hash);
            setLinkKey(key);
            setMode(key ? 'view' : 'key');
            setKeyError(!key && hash ? 'link' : null);
            setError('');
        };
        syncKey();
        window.addEventListener('hashchange', syncKey);
        return () => window.removeEventListener('hashchange', syncKey);
    }, [secret?.available, opened]);

    const first = secret?.sender?.name.split(' ')[0] ?? null;
    const once = (secret?.views_left ?? 1) <= 1;
    const screen = opened ? 'open' : !secret?.available ? 'gone' : mode;
    // After an open attempt swaps the screen, the pressed button is gone: move focus to the new heading.
    useEffect(() => {
        if (focusHeading.current && document.activeElement === document.body) {
            focusHeading.current = false;
            screenHeading.current?.focus();
        }
    }, [screen, keyError]);
    const footer = secret
        ? `${once ? 'Opens once' : `Opens ${secret.views_left} more times`} · available until ${dateTime(secret.expires_at)}`
        : '';

    async function open(key: string, fromLink: boolean) {
        if (!secret || opening.current) return;
        opening.current = true;
        focusHeading.current = true;
        setBusy(true);
        setError('');
        let shown = false;
        try {
            const keys = await secretKeys(key);
            const result = await request<{
                payload: string;
                views_left: number;
                opened_at: string;
            }>(openSecret.url(secret.token), 'POST', {
                verifier: keys.verifier,
            });
            setOpened({
                text: await keys.decrypt(result.payload),
                viewsLeft: result.views_left,
                openedAt: result.opened_at,
            });
            shown = true;
            // Drop the key from the address bar and history now that it has done its job.
            router.replace({
                url: window.location.pathname,
                preserveState: true,
                preserveScroll: true,
            });
        } catch (cause) {
            const status = (cause as { status?: number }).status;
            if (status === 422) {
                setMode('key');
                setKeyError(fromLink ? 'link' : 'wrong');
            } else if (status === 429) {
                setMode('key');
                setKeyError('throttled');
                setTimeout(
                    () =>
                        setKeyError((current) =>
                            current === 'throttled' ? null : current,
                        ),
                    60_000,
                );
            } else if (status === 410) {
                router.reload();
            } else {
                setError(
                    cause instanceof Error
                        ? cause.message
                        : 'This secret could not be opened. Please try again.',
                );
            }
        } finally {
            opening.current = shown;
            setBusy(false);
        }
    }

    function unlock(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const key = normaliseKey(keyInput);
        if (key) void open(key, false);
        else setKeyError('wrong');
    }

    async function copy() {
        if (!opened) return;
        try {
            await navigator.clipboard.writeText(opened.text);
            setCopied(true);
            setCopyError('');
        } catch {
            setCopyError(
                'Copy is unavailable. Select the secret and copy it manually.',
            );
        }
    }

    const sender = secret?.sender && (
        <div className="flex items-center gap-2.5 text-sm leading-snug text-muted-foreground">
            <Avatar
                name={secret.sender.name}
                url={secret.sender.avatar_url}
                className="border-transparent bg-muted font-semibold text-foreground md:size-9 md:text-sm"
            />
            <span>
                <strong className="text-foreground">
                    {secret.sender.name}
                </strong>
                <span className="hidden md:inline"> · </span>
                <span className="block md:inline">Mediamax Communication</span>
            </span>
        </div>
    );
    const heading = (
        <div className="flex flex-col gap-3 md:gap-3.5">
            {sender}
            <h1
                ref={screenHeading}
                tabIndex={-1}
                className="text-3xl leading-tight text-pretty"
            >
                {first
                    ? `${first} sent you a secret`
                    : 'You’ve been sent a secret'}
            </h1>
            {screen === 'view' && secret && (
                <p className="text-base text-muted-foreground">
                    {once
                        ? 'It opens once. After you view it, it’s deleted from Filemax.'
                        : `You can open it ${secret.views_left} more times until ${dateTime(secret.expires_at)}. Then it’s deleted from Filemax.`}
                </p>
            )}
        </div>
    );

    return (
        <Shell recipient>
            <Head title={first ? `${first} sent you a secret` : 'Secret'}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            {screen === 'gone' ? (
                <main className="flex flex-1 items-center justify-center px-5 py-6 md:px-10 md:pb-2">
                    <section className="flex w-full max-w-lg min-w-0 flex-col items-center gap-4 text-center md:rounded-xl md:border md:bg-background md:px-10 md:py-11">
                        <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <HugeiconsIcon
                                icon={
                                    secret?.reason === 'opened'
                                        ? LockKeyIcon
                                        : Clock01Icon
                                }
                                size={28}
                                strokeWidth={2}
                                aria-hidden="true"
                            />
                        </span>
                        {secret?.reason === 'opened' ? (
                            <>
                                <h1 ref={screenHeading} tabIndex={-1}>
                                    This secret was already opened
                                </h1>
                                <p className="max-w-sm text-base text-muted-foreground">
                                    Someone opened it on{' '}
                                    {date(secret.last_opened_at)} at{' '}
                                    {secret.last_opened_at &&
                                        new Intl.DateTimeFormat('en-GB', {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        }).format(
                                            new Date(secret.last_opened_at),
                                        )}
                                    , so it was deleted. If that wasn’t you,
                                    tell {first ?? 'the sender'}: someone else
                                    may have the link and its key.
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {secret.sender &&
                                        `Sent by ${secret.sender.name} · `}
                                    opened {dateTime(secret.last_opened_at)}
                                </p>
                            </>
                        ) : (
                            <>
                                <h1 ref={screenHeading} tabIndex={-1}>
                                    This secret is no longer available
                                </h1>
                                <p className="max-w-sm text-base text-muted-foreground">
                                    {!secret
                                        ? 'It expired, was deleted, or the link is incorrect. Ask whoever sent it to share it again.'
                                        : first
                                          ? `It expired, or ${first} deleted it. Ask ${first} to send it again.`
                                          : 'It expired, or the sender deleted it. Ask them to send it again.'}
                                </p>
                                {secret && (
                                    <p className="text-sm text-muted-foreground">
                                        {secret.sender &&
                                            `Sent by ${secret.sender.name} · `}
                                        {secret.reason === 'deleted'
                                            ? `deleted ${date(secret.revoked_at)}`
                                            : `expired ${date(secret.expires_at)}`}
                                    </p>
                                )}
                            </>
                        )}
                    </section>
                </main>
            ) : (
                <main className="flex flex-1 justify-center px-5 py-6 md:items-center md:px-10 md:pb-2">
                    {screen === 'view' && secret && (
                        <section className="flex w-full max-w-xl min-w-0 flex-col gap-6 md:gap-7 md:rounded-xl md:border md:bg-background md:p-10">
                            {heading}
                            <div className="flex flex-col items-center gap-3 rounded-lg bg-muted px-6 py-8 text-center">
                                <span className="inline-flex size-14 shrink-0 items-center justify-center rounded-full bg-background text-muted-foreground">
                                    <HugeiconsIcon
                                        icon={LockKeyIcon}
                                        size={24}
                                        aria-hidden="true"
                                    />
                                </span>
                                <p className="text-sm text-muted-foreground">
                                    Encrypted. Only your browser can unlock it.
                                </p>
                            </div>
                            <div className="flex flex-col gap-2.5">
                                <ErrorMessage>{error}</ErrorMessage>
                                <Button
                                    size="lg"
                                    disabled={busy}
                                    aria-busy={busy}
                                    onClick={() =>
                                        linkKey && void open(linkKey, true)
                                    }
                                >
                                    View secret
                                </Button>
                                <p className="text-center text-sm text-muted-foreground">
                                    {once
                                        ? 'Have somewhere ready to paste it. It won’t open again.'
                                        : 'Have somewhere ready to paste it.'}
                                </p>
                            </div>
                            <p className="text-center text-sm text-muted-foreground">
                                {footer}
                            </p>
                        </section>
                    )}
                    {screen === 'key' && secret && (
                        <section className="flex w-full max-w-md min-w-0 flex-col gap-7 md:rounded-xl md:border md:bg-background md:p-10">
                            <div className="flex flex-col items-center gap-4 text-center">
                                <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <HugeiconsIcon
                                        icon={LockKeyIcon}
                                        size={28}
                                        strokeWidth={2}
                                        aria-hidden="true"
                                    />
                                </span>
                                <h1 ref={screenHeading} tabIndex={-1}>
                                    This secret needs its key
                                </h1>
                                <p className="text-base text-muted-foreground">
                                    {keyError === 'link'
                                        ? `The key in this link doesn’t open this secret. Paste the key ${first ?? 'the sender'} sent you.`
                                        : secret.sender
                                          ? `${secret.sender.name} sent the key separately. If you don’t have it, ask ${first} for it.`
                                          : 'The key was sent separately. If you don’t have it, ask the sender for it.'}
                                </p>
                            </div>
                            <form
                                className="flex flex-col gap-4"
                                onSubmit={unlock}
                            >
                                <div className="flex flex-col gap-2">
                                    <label
                                        className="text-sm font-semibold"
                                        htmlFor="secret-key"
                                    >
                                        Key
                                    </label>
                                    {/* No name, no spellcheck: the key must never leave the browser. */}
                                    <input
                                        id="secret-key"
                                        className="font-mono tracking-wide"
                                        autoComplete="off"
                                        autoCorrect="off"
                                        autoCapitalize="characters"
                                        spellCheck={false}
                                        data-gramm="false"
                                        placeholder="XXXX-XXXX-XXXX-XXXX-XXXX"
                                        aria-invalid={keyError === 'wrong'}
                                        aria-describedby={
                                            keyError === 'wrong' ||
                                            keyError === 'throttled'
                                                ? 'key-error key-hint'
                                                : 'key-hint'
                                        }
                                        value={keyInput}
                                        onChange={(event) =>
                                            setKeyInput(event.target.value)
                                        }
                                    />
                                    {keyError === 'wrong' && (
                                        <ErrorMessage id="key-error">
                                            That key doesn’t open this secret.
                                            Check it and try again.
                                        </ErrorMessage>
                                    )}
                                    {keyError === 'throttled' && (
                                        <ErrorMessage id="key-error">
                                            Too many tries. Wait a minute, then
                                            try again.
                                        </ErrorMessage>
                                    )}
                                    <ErrorMessage>{error}</ErrorMessage>
                                    <p
                                        id="key-hint"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Dashes, spaces and letter case don’t
                                        matter.
                                    </p>
                                </div>
                                <Button
                                    size="lg"
                                    type="submit"
                                    disabled={busy || keyError === 'throttled'}
                                    aria-busy={busy}
                                >
                                    Unlock secret
                                </Button>
                                <p className="text-center text-sm text-muted-foreground">
                                    {once
                                        ? 'A wrong key doesn’t use up the view.'
                                        : 'A wrong key doesn’t use up a view.'}
                                </p>
                            </form>
                            <p className="text-center text-sm text-muted-foreground">
                                {footer}
                            </p>
                        </section>
                    )}
                    {screen === 'open' && opened && secret && (
                        <section className="flex w-full max-w-xl min-w-0 flex-col gap-6 md:gap-7 md:rounded-xl md:border md:bg-background md:p-10">
                            {heading}
                            <p
                                role="status"
                                className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-slate-700"
                            >
                                {opened.viewsLeft === 0
                                    ? 'This secret has been deleted from Filemax. Copy what you need before you leave this page.'
                                    : `You can open this secret ${opened.viewsLeft} more ${opened.viewsLeft === 1 ? 'time' : 'times'}, until ${dateTime(secret.expires_at)}.`}
                            </p>
                            <div
                                id="secret-body"
                                translate="no"
                                className="rounded-lg bg-muted px-4 py-3 font-mono text-base leading-relaxed wrap-anywhere whitespace-pre-wrap"
                            >
                                {opened.text}
                            </div>
                            <div className="flex flex-col gap-2.5">
                                <Button
                                    size="lg"
                                    aria-describedby="secret-body"
                                    onClick={() => void copy()}
                                >
                                    <HugeiconsIcon
                                        key={copied ? 'copied' : 'copy'}
                                        icon={copied ? Tick02Icon : Copy01Icon}
                                        size={20}
                                        strokeWidth={2}
                                        aria-hidden="true"
                                    />
                                    {copied ? 'Copied' : 'Copy secret'}
                                </Button>
                                <p className="sr-only" role="status">
                                    {copied ? 'Secret copied to clipboard' : ''}
                                </p>
                                <ErrorMessage>{copyError}</ErrorMessage>
                            </div>
                            <p className="text-center text-sm text-muted-foreground">
                                Unlocked in your browser ·{' '}
                                {secret.max_views === 1
                                    ? `opened ${dateTime(opened.openedAt)}`
                                    : `${secret.max_views - opened.viewsLeft} of ${secret.max_views} views used`}
                            </p>
                        </section>
                    )}
                </main>
            )}
        </Shell>
    );
}
