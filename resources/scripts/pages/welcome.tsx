import {
    Add01Icon,
    ArrowRight01Icon,
    Calendar01Icon,
    Clock01Icon,
    Copy01Icon,
    Download01Icon,
    Globe02Icon,
    ListViewIcon,
    LockKeyIcon,
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
            'Send large files to clients and teammates with one link. Here’s a quick look at what you can do.',
        points: [
            {
                icon: Upload01Icon,
                text: 'Upload large files and get one link to share',
            },
            {
                icon: UserGroupIcon,
                text: 'Share publicly, or only with your teams',
            },
            {
                icon: Clock01Icon,
                text: 'Choose how long a link lasts, and extend it',
            },
            {
                icon: Download01Icon,
                text: 'See how often your files are downloaded',
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
                            https://filemax.mediamax.it/t/8kq2vx
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
            'Send a public link anyone can open, or keep a transfer inside your teams.',
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
                icon: UserGroupIcon,
                text: (
                    <>
                        <strong>Specific teams:</strong> members sign in to
                        download. Anyone else is turned away.
                    </>
                ),
            },
            {
                icon: ArrowRight01Icon,
                text: 'Change the teams later from the transfer page. The link stays the same.',
            },
        ],
        label: 'Who can download: Specific teams is selected, sharing with Mediamax and Lenergy.',
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
                                    Specific teams
                                </strong>
                                <span className="text-sm text-muted-foreground">
                                    Signed-in members of the teams you pick.
                                </span>
                            </span>
                        </span>
                    </div>
                </div>
                <div className="flex flex-col gap-2.5">
                    <span className="text-sm font-semibold">Shared with</span>
                    <div className="flex flex-wrap gap-2">
                        <span className="inline-flex flex-wrap items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-2 text-sm">
                            <strong>Mediamax</strong>
                            <span className="text-xs text-muted-foreground">
                                8 members
                            </span>
                        </span>
                        <span className="inline-flex flex-wrap items-center gap-2 rounded-full border border-primary/20 bg-primary/10 px-3 py-2 text-sm">
                            <strong>Lenergy</strong>
                            <span className="text-xs text-muted-foreground">
                                5 members
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
        title: 'See when files are downloaded',
        description:
            'Every transfer counts its downloads, in total and per file, and shows when the last one happened.',
        points: [
            {
                icon: Download01Icon,
                text: 'Counts are download-button clicks, including your own.',
            },
            {
                icon: ViewIcon,
                text: 'See when a link was first opened, even before any downloads.',
            },
            {
                icon: ListViewIcon,
                text: 'Find every transfer you’ve sent in My transfers.',
            },
        ],
        label: 'A transfer’s Downloads card showing 9 downloads, and click counts per file.',
        figure: (
            <>
                <div className="flex flex-col gap-3.5 rounded-lg border bg-background p-5">
                    <span className="text-sm font-semibold text-muted-foreground">
                        Downloads
                    </span>
                    <div className="flex items-baseline gap-2">
                        <strong className="font-heading text-4xl leading-none font-bold tracking-tight">
                            9
                        </strong>
                        <span className="text-sm text-muted-foreground">
                            total
                        </span>
                    </div>
                    <div className="flex flex-col gap-1.5 border-t pt-2.5 text-sm text-muted-foreground">
                        <span>
                            First opened{' '}
                            <span className="font-medium text-foreground">
                                yesterday
                            </span>
                        </span>
                        <span>
                            Last downloaded{' '}
                            <span className="font-medium text-foreground">
                                today, 11:05
                            </span>
                        </span>
                    </div>
                </div>
                <div className="rounded-lg border bg-background px-5 pt-4 pb-1">
                    <div className="mb-2 flex flex-wrap items-baseline justify-between gap-x-3">
                        <span className="text-sm font-semibold">Files</span>
                        <span className="text-sm text-muted-foreground">
                            download clicks per file
                        </span>
                    </div>
                    <div className="border-t">
                        <FileRow
                            name="Lenergy_Spot30s_v3.mp4"
                            size={1200000000}
                            variant="owner"
                        >
                            <span className="min-w-3 text-right text-sm tabular-nums">
                                6
                            </span>
                        </FileRow>
                        <FileRow
                            name="Keyvisual_Autunno_2026.psd"
                            size={480000000}
                            variant="owner"
                        >
                            <span className="min-w-3 text-right text-sm tabular-nums">
                                3
                            </span>
                        </FileRow>
                    </div>
                </div>
            </>
        ),
    },
];

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
