import {
    ArrowDown01Icon,
    Download01Icon,
    FileZipIcon,
    FolderDownloadIcon,
    Link01Icon,
    MailSend01Icon,
    UserGroupIcon,
    UserIcon,
    ViewIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorMessage, PasswordBadge } from '@/components/filemax';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { plural } from '@/lib/folders';
import { bytes, date, dateTime, initials, relativeDay } from '@/lib/format';
import type { Transfer, TransferFile, TransferPerson } from '@/lib/types';
import { store as sendByEmail } from '@/routes/transfers/recipients';

/** The addresses as the server will read them, to count them on the button. */
function addresses(text: string): string[] {
    return [
        ...new Set(
            text
                .split(/[\s,;]+/)
                .map((email) => email.trim().toLowerCase())
                .filter(Boolean),
        ),
    ];
}

/** Emails the transfer: each address gets its own link, so the owner sees who opened it and what they downloaded. */
export function SendByEmail({
    transfer,
    className,
}: {
    transfer: Transfer;
    className?: string;
}) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const count = addresses(text).length;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) setText('');
            }}
        >
            <DialogTrigger
                render={<Button variant="outline" className={className} />}
            >
                <HugeiconsIcon
                    icon={MailSend01Icon}
                    size={18}
                    aria-hidden="true"
                />
                Send by email
            </DialogTrigger>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>Send by email</DialogTitle>
                    <DialogDescription>
                        Each person gets an email with their own link. You’ll
                        see who opens it and what they download.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    action={sendByEmail(transfer.id)}
                    // The link-ready screen keeps the transfer in local state, so the page must not remount.
                    options={{ preserveState: true, preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex flex-col gap-2">
                                <label
                                    className="text-sm font-semibold"
                                    htmlFor="send-emails"
                                >
                                    Email addresses
                                </label>
                                <textarea
                                    id="send-emails"
                                    name="emails"
                                    rows={3}
                                    placeholder="name@example.com, one per line or separated by commas"
                                    value={text}
                                    onChange={(event) =>
                                        setText(event.target.value)
                                    }
                                    required
                                    aria-invalid={
                                        Object.keys(errors).length > 0
                                    }
                                    aria-describedby="send-emails-hint"
                                />
                                <p
                                    id="send-emails-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Up to 10 at a time.
                                </p>
                                <ErrorMessage>
                                    {[...new Set(Object.values(errors))].join(
                                        ' ',
                                    )}
                                </ErrorMessage>
                            </div>
                            <div className="flex flex-col gap-1 rounded-lg bg-muted px-4 py-3">
                                <span className="text-sm text-muted-foreground">
                                    They’ll get
                                </span>
                                <strong className="wrap-anywhere">
                                    {transfer.title}
                                </strong>
                                <span className="text-sm text-muted-foreground">
                                    {plural(transfer.files_count)} ·{' '}
                                    {bytes(transfer.total_size)} · expires{' '}
                                    {dateTime(transfer.expires_at)}
                                </span>
                                {transfer.message && (
                                    <p className="mt-1 line-clamp-3 text-sm wrap-anywhere">
                                        Your message: “{transfer.message}”
                                    </p>
                                )}
                            </div>
                            {transfer.password_protected && (
                                <div className="flex flex-col gap-2 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-slate-700">
                                    <PasswordBadge />
                                    <p className="text-sm">
                                        The password isn’t in the email. Send it
                                        to them another way.
                                    </p>
                                </div>
                            )}
                            {transfer.visibility === 'members' && (
                                <div className="flex items-start gap-2.5 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-slate-700">
                                    <HugeiconsIcon
                                        icon={UserGroupIcon}
                                        size={18}
                                        className="mt-0.5 shrink-0 text-primary"
                                        aria-hidden="true"
                                    />
                                    <p className="text-sm">
                                        Only members of {transfer.organization}{' '}
                                        can open it. Anyone else is asked to
                                        sign in, and turned away if they aren’t
                                        a member.
                                    </p>
                                </div>
                            )}
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    type="button"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={processing || count === 0}
                                    aria-busy={processing}
                                >
                                    {count > 1
                                        ? `Send to ${count} people`
                                        : 'Send'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** "today, 11:05" for the last two days, the date and time before that. */
function when(value: string): string {
    const day = relativeDay(value);
    return day === 'today' || day === 'yesterday'
        ? `${day}, ${new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit' }).format(new Date(value))}`
        : dateTime(value);
}

function summary(person: TransferPerson): string {
    const downloads = person.activity.filter((a) => a.kind === 'downloaded');
    const previews = person.activity.filter((a) => a.kind === 'previewed');
    const parts = [];
    if (downloads.some((a) => !a.file_id && !a.folder)) {
        parts.push('Downloaded all files');
    } else if (downloads.length) {
        const files = downloads.filter((a) => a.file_id).length;
        const folders = downloads.length - files;
        parts.push(
            `Downloaded ${[files && plural(files), folders && plural(folders, 'folder')].filter(Boolean).join(' and ')}`,
        );
    }
    if (previews.length) parts.push(`Previewed ${plural(previews.length)}`);
    if (parts.length) return parts.join(' · ');
    return person.activity.length
        ? 'Opened the link · nothing downloaded yet'
        : `Sent ${date(person.sent_at)} · not opened yet`;
}

/** Who opened, previewed and downloaded the transfer: people it was emailed to, and signed-in members. */
export function People({
    people,
    files,
    transfer,
    canSend,
}: {
    people: TransferPerson[];
    files: TransferFile[];
    transfer: Transfer;
    /** Whether the viewer may still email the transfer, which "Send again" does. */
    canSend: boolean;
}) {
    const names = new Map(files.map((file) => [file.id, file.original_name]));
    const label = (activity: TransferPerson['activity'][number]) => {
        const times = activity.times > 1 ? ` · ${activity.times} times` : '';
        const file = activity.file_id
            ? (names.get(activity.file_id) ?? 'a file')
            : null;
        if (activity.kind === 'opened') return 'Opened the link';
        if (activity.kind === 'previewed') return `Previewed ${file}${times}`;
        if (file) return `Downloaded ${file}${times}`;
        return activity.folder
            ? `Downloaded the folder ${activity.folder} as a zip${times}`
            : `Downloaded all files as a zip${times}`;
    };
    const icon = (activity: TransferPerson['activity'][number]) =>
        activity.kind === 'opened'
            ? Link01Icon
            : activity.kind === 'previewed'
              ? ViewIcon
              : activity.file_id
                ? Download01Icon
                : activity.folder
                  ? FolderDownloadIcon
                  : FileZipIcon;

    return (
        <section className="flex flex-col gap-2" aria-labelledby="people-title">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h2
                    id="people-title"
                    className="font-sans text-sm font-semibold tracking-normal"
                >
                    People
                </h2>
                <span className="text-sm text-muted-foreground">
                    {transfer.organization
                        ? `People you emailed and signed-in members of ${transfer.organization}`
                        : 'People you emailed'}
                </span>
            </div>
            {people.length === 0 ? (
                <p className="rounded-lg border border-dashed px-5 py-6 text-muted-foreground">
                    No one yet. Send the link by email to see who opens it and
                    what they download.
                </p>
            ) : (
                <ul className="border-t">
                    {people.map((person) => (
                        <Collapsible
                            key={person.email}
                            render={<li className="border-b" />}
                        >
                            <CollapsibleTrigger className="group flex min-h-16 w-full cursor-pointer items-center gap-3 rounded-md py-3 text-left hover:bg-muted/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                {person.last_active_at ? (
                                    <span
                                        aria-hidden="true"
                                        className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground"
                                    >
                                        {initials(person.name ?? person.email)}
                                    </span>
                                ) : (
                                    <span
                                        aria-hidden="true"
                                        className="inline-flex size-9 shrink-0 items-center justify-center rounded-full border border-dashed border-input text-muted-foreground"
                                    >
                                        <HugeiconsIcon
                                            icon={UserIcon}
                                            size={18}
                                        />
                                    </span>
                                )}
                                <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <span className="flex min-w-0 flex-wrap items-center gap-2">
                                        <strong className="text-sm break-all">
                                            {person.name ?? person.email}
                                        </strong>
                                        <Badge
                                            variant={
                                                person.name
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {person.name ? 'Member' : 'Email'}
                                        </Badge>
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {summary(person)}
                                    </span>
                                </span>
                                {person.last_active_at && (
                                    <span className="hidden shrink-0 text-sm text-muted-foreground sm:inline">
                                        {when(person.last_active_at)}
                                    </span>
                                )}
                                <HugeiconsIcon
                                    icon={ArrowDown01Icon}
                                    size={16}
                                    className="shrink-0 text-muted-foreground transition-transform duration-160 ease-out group-data-panel-open:rotate-180 motion-reduce:transition-none"
                                    aria-hidden="true"
                                />
                            </CollapsibleTrigger>
                            <CollapsibleContent className="flex flex-col gap-2.5 pb-4 pl-12">
                                <p className="text-sm text-muted-foreground">
                                    {[
                                        person.name && person.email,
                                        person.sent_at &&
                                            `${person.name ? 'Also sent' : 'Sent'} by email ${dateTime(person.sent_at)}`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                {person.activity.length > 0 ? (
                                    <ul className="flex flex-col gap-2">
                                        {person.activity.map((activity) => (
                                            <li
                                                key={`${activity.kind}:${activity.file_id ?? activity.folder ?? ''}`}
                                                className="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-sm"
                                            >
                                                <HugeiconsIcon
                                                    icon={icon(activity)}
                                                    size={16}
                                                    className="shrink-0 text-muted-foreground"
                                                    aria-hidden="true"
                                                />
                                                <span className="min-w-0 flex-1 wrap-anywhere">
                                                    {label(activity)}
                                                </span>
                                                <span className="shrink-0 text-muted-foreground tabular-nums">
                                                    {when(activity.last_at)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <>
                                        <p className="text-sm text-muted-foreground">
                                            Nothing yet: opening the email alone
                                            doesn’t count.
                                        </p>
                                        {canSend && (
                                            <Form
                                                action={sendByEmail(
                                                    transfer.id,
                                                )}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="flex flex-col gap-2"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <input
                                                            type="hidden"
                                                            name="emails[]"
                                                            value={person.email}
                                                        />
                                                        <Button
                                                            type="submit"
                                                            variant="outline"
                                                            size="sm"
                                                            className="self-start"
                                                            disabled={
                                                                processing
                                                            }
                                                            aria-label={`Send the email to ${person.email} again`}
                                                        >
                                                            Send again
                                                        </Button>
                                                        <ErrorMessage>
                                                            {Object.values(
                                                                errors,
                                                            ).join(' ')}
                                                        </ErrorMessage>
                                                    </>
                                                )}
                                            </Form>
                                        )}
                                    </>
                                )}
                            </CollapsibleContent>
                        </Collapsible>
                    ))}
                </ul>
            )}
        </section>
    );
}
