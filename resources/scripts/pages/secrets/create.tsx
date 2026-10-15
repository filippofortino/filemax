import { LockKeyIcon, Tick02Icon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { CopyLink, ErrorMessage, RadioPill, Shell } from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { dateTime } from '@/lib/format';
import { request } from '@/lib/http';
import { encryptSecret, formatKey } from '@/lib/secret';
import { create, store } from '@/routes/secrets';
import { index } from '@/routes/transfers';

const expiries = [
    { hours: 1, label: '1 hour' },
    { hours: 24, label: '1 day' },
    { hours: 168, label: '7 days' },
] as const;

type Ready = { url: string; key: string; expiresAt: string; maxViews: number };

export default function CreateSecret() {
    const [secret, setSecret] = useState('');
    const [label, setLabel] = useState('');
    const [maxViews, setMaxViews] = useState<1 | 5>(1);
    const [hours, setHours] = useState<1 | 24 | 168>(24);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [mounted, setMounted] = useState(false);
    const [ready, setReady] = useState<Ready | null>(null);
    const [split, setSplit] = useState(false);
    const readyHeading = useRef<HTMLHeadingElement>(null);

    useEffect(() => setMounted(true), []);
    useEffect(() => {
        if (ready && document.activeElement === document.body)
            readyHeading.current?.focus();
    }, [ready]);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const { key, payload, verifier } = await encryptSecret(secret);
            const result = await request<{
                url: string;
                expires_at: string;
                max_views: number;
            }>(store.url(), 'POST', {
                label: label.trim() || null,
                payload,
                verifier,
                max_views: maxViews,
                expires_in_hours: hours,
            });
            setReady({
                url: result.url,
                key,
                expiresAt: result.expires_at,
                maxViews: result.max_views,
            });
            setSecret('');
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'This secret could not be created. Please try again.',
            );
        } finally {
            setBusy(false);
        }
    }

    if (ready) {
        const link = split ? ready.url : `${ready.url}#${ready.key}`;
        return (
            <Shell active="secret">
                <Head title="Your secret is ready" />
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
                                Your secret is ready
                            </h1>
                            <p className="text-muted-foreground">
                                {ready.maxViews === 1
                                    ? 'Opens once'
                                    : `Opens up to ${ready.maxViews} times`}{' '}
                                · expires {dateTime(ready.expiresAt)}
                            </p>
                        </div>
                        <div className="flex flex-col gap-1.5 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-slate-700">
                            <strong className="inline-flex items-center gap-2">
                                <HugeiconsIcon
                                    icon={LockKeyIcon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                Copy it now
                            </strong>
                            <p className="text-sm">
                                Filemax never sees the key, so it can’t show
                                this link or the key again after you leave this
                                page.
                            </p>
                        </div>
                        <div className="flex items-start justify-between gap-6 rounded-lg border px-4 py-3">
                            <div className="flex flex-col gap-1">
                                <span
                                    id="split-label"
                                    className="font-semibold"
                                >
                                    Send the key separately
                                </span>
                                <p
                                    id="split-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    The link alone won’t open the secret. Send
                                    the key another way, by phone or chat.
                                </p>
                            </div>
                            <Switch
                                className="mt-0.5"
                                checked={split}
                                onCheckedChange={(checked) => setSplit(checked)}
                                aria-labelledby="split-label"
                                aria-describedby="split-hint"
                            />
                        </div>
                        <CopyLink key={link} url={link} />
                        {split && (
                            <CopyLink
                                noun="key"
                                url={formatKey(ready.key)}
                                hint="Whoever opens the link is asked for this key. It’s short enough to read out over the phone."
                            />
                        )}
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Link href={index()} className="font-semibold">
                                View in My transfers
                            </Link>
                            <Link
                                href={create()}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                                onClick={() => {
                                    setReady(null);
                                    setSplit(false);
                                    setLabel('');
                                    setMaxViews(1);
                                    setHours(24);
                                }}
                            >
                                Share another secret
                            </Link>
                        </div>
                    </section>
                </main>
            </Shell>
        );
    }

    const until = new Date(Date.now() + hours * 3_600_000).toISOString();
    return (
        <Shell active="secret">
            <Head title="New secret" />
            <main className="flex flex-1 justify-center bg-muted px-5 py-6 md:p-10">
                <form
                    className="flex w-full max-w-2xl flex-col gap-6 rounded-xl border bg-background px-5 py-7 md:p-10"
                    onSubmit={submit}
                >
                    <div className="flex flex-col gap-2">
                        <h1>New secret</h1>
                        <p className="text-base text-muted-foreground">
                            It’s encrypted in your browser before it leaves this
                            page. Filemax stores only scrambled text and never
                            sees the key.
                        </p>
                    </div>
                    <div className="flex flex-col gap-2">
                        <label
                            className="text-sm font-semibold"
                            htmlFor="secret-text"
                        >
                            Secret
                        </label>
                        {/* No name, no spellcheck: the plain text must never leave the browser. */}
                        <textarea
                            id="secret-text"
                            required
                            maxLength={10000}
                            spellCheck={false}
                            autoComplete="off"
                            autoCorrect="off"
                            autoCapitalize="off"
                            data-gramm="false"
                            placeholder="A password, an API key, anything private"
                            aria-describedby="secret-hint"
                            className="min-h-40 font-mono leading-relaxed"
                            value={secret}
                            onChange={(event) => setSecret(event.target.value)}
                            disabled={busy}
                        />
                        <div
                            id="secret-hint"
                            className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground"
                        >
                            <span className="inline-flex items-center gap-1.5">
                                <HugeiconsIcon
                                    icon={LockKeyIcon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                Encrypted in your browser
                            </span>
                            <span className="tabular-nums">
                                {secret.length.toLocaleString('en-GB')} / 10,000
                                characters
                            </span>
                        </div>
                    </div>
                    <div className="flex flex-col gap-2">
                        <label
                            className="text-sm font-semibold"
                            htmlFor="secret-label"
                        >
                            Label{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </label>
                        <input
                            id="secret-label"
                            maxLength={120}
                            autoComplete="off"
                            aria-describedby="label-hint"
                            value={label}
                            onChange={(event) => setLabel(event.target.value)}
                            disabled={busy}
                        />
                        <p
                            id="label-hint"
                            className="text-sm text-muted-foreground"
                        >
                            Only you see it, in My transfers. It isn’t
                            encrypted.
                        </p>
                    </div>
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <fieldset>
                            <legend className="mb-2.5 text-sm font-semibold">
                                Opens
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                <RadioPill
                                    name="views"
                                    checked={maxViews === 1}
                                    onChange={() => setMaxViews(1)}
                                    disabled={busy}
                                >
                                    Once
                                </RadioPill>
                                <RadioPill
                                    name="views"
                                    checked={maxViews === 5}
                                    onChange={() => setMaxViews(5)}
                                    disabled={busy}
                                >
                                    Up to 5 times
                                </RadioPill>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend className="mb-2.5 text-sm font-semibold">
                                Expires in
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {expiries.map((expiry) => (
                                    <RadioPill
                                        key={expiry.hours}
                                        name="expiry"
                                        checked={hours === expiry.hours}
                                        onChange={() => setHours(expiry.hours)}
                                        disabled={busy}
                                    >
                                        {expiry.label}
                                    </RadioPill>
                                ))}
                            </div>
                        </fieldset>
                    </div>
                    <p className="text-sm text-muted-foreground" role="status">
                        {maxViews === 1
                            ? `Deleted after the first view, or in ${expiries.find((expiry) => expiry.hours === hours)?.label} if nobody opens it.`
                            : `Deleted after 5 views, or at ${dateTime(until)}, whichever comes first.`}
                    </p>
                    <div className="flex flex-col gap-3">
                        <ErrorMessage>{error}</ErrorMessage>
                        <Button
                            size="lg"
                            type="submit"
                            disabled={!mounted || busy}
                            aria-describedby="submit-hint"
                        >
                            {busy ? 'Encrypting…' : 'Create secret link'}
                        </Button>
                        <p
                            id="submit-hint"
                            className="text-center text-sm text-muted-foreground"
                        >
                            You’ll copy the link on the next screen. Filemax
                            can’t show it to you again later.
                        </p>
                    </div>
                </form>
            </main>
        </Shell>
    );
}
