import {
    Add01Icon,
    ArrowRight01Icon,
    Calendar01Icon,
    Clock01Icon,
    Copy01Icon,
    Download01Icon,
    EncryptIcon,
    Globe02Icon,
    Image01Icon,
    LockedIcon,
    LockKeyIcon,
    PackageReceiveIcon,
    Tick02Icon,
    Upload01Icon,
    UserGroupIcon,
    ViewIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { Brand, FileRow } from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import type { SharedProps } from '@/lib/types';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

const steps = [
    {
        title: 'Welcome to Filemax',
        description:
            'Send large files, share a secret or ask for files, each with one link. Here’s a quick look at what you can do.',
        points: [
            {
                icon: Upload01Icon,
                text: 'Upload large files and get one link to share',
            },
            {
                icon: UserGroupIcon,
                text: 'Share publicly, or only with your organization',
            },
            {
                icon: Clock01Icon,
                text: 'Choose how long a link lasts, and extend it',
            },
            {
                icon: Download01Icon,
                text: 'See how often your files are downloaded',
            },
            {
                icon: EncryptIcon,
                text: 'Share a password or a note as a secret that opens once',
            },
            {
                icon: PackageReceiveIcon,
                text: 'Ask anyone for files with a Drop, no account needed',
            },
        ],
        label: 'The link-ready card: a share link and the two files it holds.',
        figure: (
            <div className="flex flex-col gap-5 rounded-xl border bg-background p-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <span className="inline-flex size-14 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <HugeiconsIcon
                            icon={Tick02Icon}
                            size={26}
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                    </span>
                    <span className="font-heading text-2xl font-bold tracking-tight">
                        Your link is ready
                    </span>
                    <span className="text-sm text-muted-foreground">
                        2 files · 1.68 GB · expires in 7 days
                    </span>
                </div>
                <div className="flex flex-col gap-2">
                    <span className="text-sm font-semibold">
                        Share this link
                    </span>
                    <div className="flex flex-wrap gap-2">
                        <span className="flex h-11 min-w-0 grow basis-48 items-center overflow-hidden rounded-md border border-input px-3 text-base whitespace-nowrap">
                            https://filemax.dev/t/8kq2vx
                        </span>
                        <span className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-primary px-5 text-sm font-semibold whitespace-nowrap text-primary-foreground">
                            <HugeiconsIcon
                                icon={Copy01Icon}
                                size={16}
                                aria-hidden="true"
                            />
                            Copy link
                        </span>
                    </div>
                </div>
                <div>
                    <FileRow name="Lenergy_Spot30s_v3.mp4" size={1200000000} />
                    <FileRow
                        name="Keyvisual_Autunno_2026.psd"
                        size={480000000}
                    />
                </div>
            </div>
        ),
    },
    {
        title: 'Drop files, get one link',
        description:
            'Drag files anywhere onto New transfer, or browse for them. Add a title and a message so whoever opens the link knows what it is.',
        points: [
            {
                icon: LockKeyIcon,
                text: 'Your files stay private until your link is ready.',
            },
            {
                icon: Tick02Icon,
                text: 'If the connection drops, completed files are safe. A retry sends only what’s missing.',
            },
            {
                icon: Copy01Icon,
                text: 'When the upload finishes, copy the link and send it however you like.',
            },
        ],
        label: 'Files dropped on New transfer, uploading with overall and per-file progress.',
        figure: (
            <>
                <div className="flex items-center gap-4 rounded-xl border-2 border-dashed border-slate-300 bg-background p-5">
                    <span className="inline-flex size-14 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <HugeiconsIcon
                            icon={Upload01Icon}
                            size={26}
                            strokeWidth={2}
                            aria-hidden="true"
                        />
                    </span>
                    <span className="flex flex-col gap-1">
                        <span className="font-heading text-xl font-bold tracking-tight">
                            Drop files here
                        </span>
                        <span className="text-sm text-muted-foreground">
                            Anywhere on this page works.
                        </span>
                    </span>
                </div>
                <div className="flex flex-col gap-3 rounded-xl border bg-background p-5">
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="font-heading text-2xl font-bold tracking-tight">
                            Uploading…
                        </span>
                        <strong className="font-heading text-2xl text-primary">
                            62%
                        </strong>
                    </div>
                    <progress value={62} max={100} />
                    <div className="flex justify-between gap-2 text-sm text-muted-foreground">
                        <span>1.04 GB of 1.68 GB</span>
                        <span>About 40 seconds left</span>
                    </div>
                </div>
                <div className="flex flex-col gap-2">
                    <div className="overflow-hidden rounded-lg border bg-background px-3">
                        <FileRow
                            name="Keyvisual_Autunno_2026.psd"
                            size={480000000}
                            variant="upload"
                        >
                            <span className="flex items-center gap-1 text-sm font-semibold text-emerald-700">
                                <HugeiconsIcon
                                    icon={Tick02Icon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                Done
                            </span>
                        </FileRow>
                    </div>
                    <div className="overflow-hidden rounded-lg border bg-background px-3">
                        <FileRow
                            name="Lenergy_Spot30s_v3.mp4"
                            size={1200000000}
                            variant="upload"
                        >
                            <span className="text-sm text-primary">47%</span>
                        </FileRow>
                        <progress
                            className="h-1"
                            value={564000000}
                            max={1200000000}
                        />
                    </div>
                </div>
            </>
        ),
    },
    {
        title: 'Choose who can download',
        description:
            'Send a public link anyone can open, or keep a transfer inside your organization.',
        points: [
            {
                icon: Globe02Icon,
                text: (
                    <>
                        <strong>Public link:</strong> anyone with the link can
                        download, no account needed.
                    </>
                ),
            },
            {
                icon: LockKeyIcon,
                text: (
                    <>
                        <strong>Password:</strong> add one to a public link, so
                        the link alone isn’t enough.
                    </>
                ),
            },
            {
                icon: UserGroupIcon,
                text: (
                    <>
                        <strong>Members only:</strong> members of your
                        organization sign in to download. Anyone else is turned
                        away.
                    </>
                ),
            },
            {
                icon: ArrowRight01Icon,
                text: 'Switch organization in the header. Links sent from Personal carry only your name.',
            },
        ],
        label: 'Who can download: Members only is selected, sharing with members of Mediamax.',
        figure: (
            <div className="flex flex-col gap-5 rounded-xl border bg-background p-6">
                <div className="flex flex-col gap-2.5">
                    <span className="text-sm font-semibold">
                        Who can download
                    </span>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <span className="flex flex-col gap-2 rounded-lg border border-input p-4">
                            <span className="flex items-center justify-between">
                                <HugeiconsIcon
                                    icon={Globe02Icon}
                                    size={22}
                                    aria-hidden="true"
                                />
                                <span className="size-4 rounded-full border border-slate-300 bg-white" />
                            </span>
                            <span className="flex flex-col">
                                <strong className="text-base">
                                    Public link
                                </strong>
                                <span className="text-sm text-muted-foreground">
                                    Anyone with the link. No account needed.
                                </span>
                            </span>
                        </span>
                        <span className="flex flex-col gap-2 rounded-lg border border-primary bg-primary/5 p-4 ring-1 ring-primary">
                            <span className="flex items-center justify-between text-primary">
                                <HugeiconsIcon
                                    icon={UserGroupIcon}
                                    size={22}
                                    aria-hidden="true"
                                />
                                <span className="size-4 rounded-full border-5 border-primary bg-white" />
                            </span>
                            <span className="flex flex-col">
                                <strong className="text-base text-primary">
                                    Members only
                                </strong>
                                <span className="text-sm text-muted-foreground">
                                    Signed-in members of Mediamax.
                                </span>
                            </span>
                        </span>
                    </div>
                </div>
                <div className="flex flex-col gap-2.5">
                    <span className="text-sm font-semibold">Shared with</span>
                    <div className="flex flex-wrap gap-2">
                        <span className="inline-flex flex-wrap items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-2 text-sm">
                            <strong>Members of Mediamax</strong>
                            <span className="text-xs text-muted-foreground">
                                12 members
                            </span>
                        </span>
                    </div>
                </div>
                <span className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-slate-700">
                    Members sign in to download. The link alone is not enough.
                </span>
            </div>
        ),
    },
    {
        title: 'Pick how long it lasts',
        description:
            'A link lasts 1, 7, 14 or 30 days. Need more time? Extend the transfer from its page.',
        points: [
            {
                icon: Clock01Icon,
                text: 'After a link expires, recipients can no longer download.',
            },
            {
                icon: Add01Icon,
                text: 'Extending adds 7 days and keeps the same link, so there’s nothing to resend.',
            },
        ],
        label: 'Link expires set to 14 days, then a transfer that expires in 2 days with an Extend by 7 days button.',
        figure: (
            <>
                <div className="flex flex-col gap-2.5 rounded-xl border bg-background p-6">
                    <span className="text-sm font-semibold">Link expires</span>
                    <div className="flex flex-wrap gap-2">
                        {[1, 7, 14, 30].map((days) => (
                            <span
                                key={days}
                                className={cn(
                                    'inline-flex h-10 items-center rounded-full border border-input px-3',
                                    days === 14 &&
                                        'border-primary bg-primary/5 font-semibold text-primary ring-1 ring-primary',
                                )}
                            >
                                {days} {days === 1 ? 'day' : 'days'}
                            </span>
                        ))}
                    </div>
                    <span className="text-sm text-muted-foreground">
                        Available for 14 days.
                    </span>
                </div>
                <div className="flex flex-col gap-4 rounded-xl border bg-background p-6">
                    <div className="flex flex-col gap-1.5">
                        <span className="font-heading text-xl font-bold tracking-tight">
                            Master spot + visual approvato
                        </span>
                        <span className="text-sm text-muted-foreground">
                            Expires in 2 days · 2 files · 1.68 GB
                        </span>
                    </div>
                    <span className="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-input bg-background px-5 text-sm font-semibold whitespace-nowrap">
                        <HugeiconsIcon
                            icon={Calendar01Icon}
                            size={18}
                            aria-hidden="true"
                        />
                        Extend by 7 days
                    </span>
                    <span className="text-center text-xs text-muted-foreground">
                        Extending keeps the same link.
                    </span>
                </div>
            </>
        ),
    },
    {
        title: 'Share a secret that opens once',
        description:
            'Send a password, an API key or a private note from Secrets. It’s encrypted in your browser before it leaves the page, and deleted once it has been viewed.',
        points: [
            {
                icon: LockedIcon,
                text: 'Filemax stores only scrambled text and never sees the key.',
            },
            {
                icon: ViewIcon,
                text: 'Let it open once or up to 5 times, and expire in 1 hour, 1 day or 7 days.',
            },
            {
                icon: Copy01Icon,
                text: 'Copy the link when it’s ready, or send the key separately. Filemax can’t show either again later.',
            },
        ],
        label: 'New secret: a token in the Secret field, set to open once and expire in 1 day, then the sealed box its recipient sees.',
        figure: (
            <>
                <div className="flex flex-col gap-4 rounded-xl border bg-background p-6">
                    <div className="flex flex-col gap-2">
                        <span className="text-sm font-semibold">Secret</span>
                        <span className="block rounded-md border border-input px-3.5 py-3 font-mono text-sm leading-relaxed wrap-anywhere">
                            Token sviluppatore Google Ads:
                            <br />
                            Zk3p9QwL7xVb2NcTq8Rm
                        </span>
                        <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                            <HugeiconsIcon
                                icon={LockedIcon}
                                size={16}
                                aria-hidden="true"
                            />
                            Encrypted in your browser
                        </span>
                    </div>
                    <Pills label="Opens" options={['Once', 'Up to 5 times']} />
                    <Pills
                        label="Expires in"
                        options={['1 hour', '1 day', '7 days']}
                        selected="1 day"
                    />
                </div>
                <div className="flex items-center gap-3 rounded-xl border bg-background p-4">
                    <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <HugeiconsIcon
                            icon={EncryptIcon}
                            size={20}
                            aria-hidden="true"
                        />
                    </span>
                    <span className="flex flex-col">
                        <strong className="text-sm">
                            Filippo sent you a secret
                        </strong>
                        <span className="text-sm text-muted-foreground">
                            Encrypted. Only your browser can unlock it.
                        </span>
                    </span>
                </div>
            </>
        ),
    },
    {
        title: 'Ask for files with a Drop',
        description:
            'A Drop is a link that lets anyone send you files, no account needed. Share it with a client and what they upload comes straight to you.',
        points: [
            {
                icon: Upload01Icon,
                text: 'A Drop only uploads. Whoever uses it can’t see your transfers or anyone else’s files.',
            },
            {
                icon: Clock01Icon,
                text: 'Choose how long it accepts uploads, and limit file types and sizes if you need to.',
            },
            {
                icon: PackageReceiveIcon,
                text: 'What arrives lands in My transfers, under Received, and you get an email.',
            },
        ],
        label: 'A Drop’s upload page asking for photos and videos with its rules, then the files Giulia Neri sent, listed under Received.',
        figure: (
            <>
                <div className="flex flex-col gap-4 rounded-xl border bg-background p-5">
                    <div className="flex flex-col gap-3">
                        <div className="flex items-center gap-2.5 text-sm leading-snug text-muted-foreground">
                            <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold text-foreground">
                                FF
                            </span>
                            <span>
                                <strong className="text-foreground">
                                    Filippo Fortino
                                </strong>{' '}
                                is asking for files
                            </span>
                        </div>
                        <span className="font-heading text-xl font-bold tracking-tight">
                            Foto e video — Fiera Rimini
                        </span>
                    </div>
                    <div className="flex flex-col gap-2.5 rounded-lg bg-muted px-4 py-3 text-sm text-slate-700">
                        {[
                            { icon: Clock01Icon, text: 'Open for 14 days' },
                            {
                                icon: Image01Icon,
                                text: 'Images and videos only',
                            },
                            {
                                icon: Upload01Icon,
                                text: 'Up to 2 GB per file, 10 GB in total',
                            },
                        ].map((rule) => (
                            <span
                                key={rule.text}
                                className="flex items-center gap-2.5"
                            >
                                <HugeiconsIcon
                                    icon={rule.icon}
                                    size={16}
                                    className="shrink-0"
                                    aria-hidden="true"
                                />
                                {rule.text}
                            </span>
                        ))}
                    </div>
                    <div className="flex items-center gap-3 rounded-lg border-2 border-dashed border-slate-300 p-4">
                        <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <HugeiconsIcon
                                icon={Upload01Icon}
                                size={20}
                                aria-hidden="true"
                            />
                        </span>
                        <span className="font-heading text-lg font-bold tracking-tight">
                            Drop files here
                        </span>
                    </div>
                </div>
                <div className="flex flex-col gap-3 rounded-xl border bg-background p-5">
                    <div className="flex items-center justify-between gap-3">
                        <span className="text-sm font-semibold">Received</span>
                        <span className="inline-flex h-5 shrink-0 items-center rounded-full border border-blue-200 bg-blue-50 px-2 text-xs font-semibold text-primary">
                            1 new
                        </span>
                    </div>
                    <div className="flex items-center gap-3 border-t pt-3">
                        <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground">
                            GN
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col">
                            <strong className="text-sm">Giulia Neri</strong>
                            <span className="text-sm text-muted-foreground">
                                3 files · 514 MB
                            </span>
                        </span>
                        <span className="hidden max-w-42.5 min-w-0 items-center gap-1.5 rounded-full border bg-muted px-2.5 py-1 text-xs font-semibold text-slate-700 sm:inline-flex">
                            <HugeiconsIcon
                                icon={PackageReceiveIcon}
                                size={13}
                                className="shrink-0"
                                aria-hidden="true"
                            />
                            <span className="truncate">
                                Foto e video — Fiera Rimini
                            </span>
                        </span>
                    </div>
                </div>
            </>
        ),
    },
];

/** A row of illustrative radio pills, matching the real form's RadioPill look. */
function Pills({
    label,
    options,
    selected = options[0],
}: {
    label: string;
    options: string[];
    selected?: string;
}) {
    return (
        <div className="flex flex-col gap-2.5">
            <span className="text-sm font-semibold">{label}</span>
            <div className="flex flex-wrap gap-2">
                {options.map((option) => (
                    <span
                        key={option}
                        className={cn(
                            'inline-flex h-10 items-center rounded-full border border-input px-3',
                            option === selected &&
                                'border-primary bg-primary/5 font-semibold text-primary ring-1 ring-primary',
                        )}
                    >
                        {option}
                    </span>
                ))}
            </div>
        </div>
    );
}

export default function Welcome() {
    const firstName =
        usePage<SharedProps>().props.auth.user?.name.split(' ')[0];
    const [step, setStep] = useState(0);
    const heading = useRef<HTMLHeadingElement>(null);
    const current = steps[step];
    const isLast = step === steps.length - 1;
    function go(next: number) {
        flushSync(() => setStep(next));
        heading.current?.focus();
    }
    return (
        <main className="flex min-h-svh flex-col items-center justify-center gap-8 bg-muted px-5 py-8">
            <Head title="Welcome" />
            <Brand large />
            <section
                aria-labelledby="step-title"
                className="grid w-full max-w-260 gap-2 rounded-xl border bg-background p-2 lg:min-h-150 lg:grid-cols-2"
            >
                <figure
                    key={step}
                    role="img"
                    aria-label={current.label}
                    className="flex min-w-0 flex-col justify-center gap-3 rounded-lg bg-muted p-5 transition-opacity duration-300 ease-out sm:p-8 lg:p-10 starting:opacity-0"
                >
                    {current.figure}
                </figure>
                <div className="flex min-w-0 flex-col gap-6 px-3 py-5 sm:px-6 lg:px-10 lg:py-8">
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            Step {step + 1} of {steps.length}
                        </p>
                        {!isLast && (
                            <Link
                                href={home()}
                                className="-my-3 py-3 text-sm font-semibold text-muted-foreground hover:text-foreground"
                            >
                                Skip intro
                            </Link>
                        )}
                    </div>
                    <div
                        key={step}
                        className="flex flex-1 flex-col justify-center gap-4 transition-opacity duration-300 ease-out starting:opacity-0"
                    >
                        <h1 id="step-title" ref={heading} tabIndex={-1}>
                            {step === 0 && firstName
                                ? `${current.title}, ${firstName}`
                                : current.title}
                        </h1>
                        <p className="text-base text-muted-foreground">
                            {current.description}
                        </p>
                        <ul className="mt-2 flex flex-col gap-3">
                            {current.points.map((point, index) => (
                                <li
                                    key={index}
                                    className="flex items-center gap-3"
                                >
                                    <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-slate-700">
                                        <HugeiconsIcon
                                            icon={point.icon}
                                            size={16}
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span>{point.text}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div
                            aria-hidden="true"
                            className="flex items-center gap-1.5"
                        >
                            {steps.map((item, index) => (
                                <span
                                    key={item.title}
                                    className={cn(
                                        'h-2 w-2 rounded-full bg-slate-300 transition-[width,background-color] duration-300 ease-out',
                                        index === step && 'w-6 bg-primary',
                                    )}
                                />
                            ))}
                        </div>
                        <div className="flex w-full flex-wrap gap-3 sm:w-auto">
                            {step > 0 && (
                                <Button
                                    variant="outline"
                                    className="grow sm:grow-0"
                                    onClick={() => go(step - 1)}
                                >
                                    Back
                                </Button>
                            )}
                            {isLast ? (
                                <Link
                                    href={home()}
                                    className={cn(
                                        buttonVariants(),
                                        'grow whitespace-normal sm:grow-0',
                                    )}
                                >
                                    <HugeiconsIcon
                                        icon={Add01Icon}
                                        size={16}
                                        aria-hidden="true"
                                    />
                                    Create your first transfer
                                </Link>
                            ) : (
                                <Button
                                    className="grow sm:grow-0"
                                    onClick={() => go(step + 1)}
                                >
                                    Next
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
            </section>
        </main>
    );
}
