import {
    Cancel01Icon,
    Copy01Icon,
    File01Icon,
    FileZipIcon,
    Image01Icon,
    Logout01Icon,
    Settings01Icon,
    Tick02Icon,
    Video01Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Avatar } from '@/components/avatar';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { bytes } from '@/lib/format';
import type { SharedProps, Team } from '@/lib/types';
import { cn } from '@/lib/utils';
import { home, logout } from '@/routes';
import { settings } from '@/routes/account';
import { index as teams } from '@/routes/teams';
import { index as transfers } from '@/routes/transfers';

export function Brand({ large = false }: { large?: boolean }) {
    return (
        <Link
            href={home()}
            className={cn(
                'inline-flex shrink-0 items-center gap-2 font-heading text-xl font-bold tracking-tight text-foreground hover:text-foreground md:gap-2.5 md:text-2xl',
                large && 'gap-3 text-3xl md:gap-3 md:text-3xl',
            )}
        >
            <svg
                width={large ? 32 : 24}
                height={large ? 32 : 24}
                viewBox="0 0 24 24"
                fill="none"
                aria-hidden="true"
            >
                <rect
                    x="2"
                    y="2"
                    width="20"
                    height="20"
                    rx="5"
                    fill="#2140E0"
                />
                <path
                    d="M12 17V8M8.5 11.5 12 8l3.5 3.5"
                    stroke="white"
                    strokeWidth="2.2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
            </svg>
            <span>Filemax</span>
        </Link>
    );
}
export function Shell({
    children,
    active = 'new',
    recipient = false,
    recipientAccount = false,
    headerAction,
}: {
    children: ReactNode;
    active?: 'new' | 'transfers' | 'teams' | 'account';
    recipient?: boolean;
    recipientAccount?: boolean;
    headerAction?: ReactNode;
}) {
    const user = usePage<SharedProps>().props.auth.user;
    return (
        <div
            className={cn(
                'flex min-h-svh flex-col',
                recipient && 'bg-background md:bg-muted',
            )}
        >
            <header
                className={cn(
                    'relative flex min-h-14 shrink-0 flex-wrap items-center justify-between gap-x-3 border-b bg-background px-5 md:h-16 md:flex-nowrap md:gap-5 md:px-8',
                    recipient &&
                        !recipientAccount &&
                        'md:border-0 md:bg-transparent',
                )}
            >
                <Brand />
                {!recipient && (
                    <nav
                        aria-label="Main navigation"
                        className={cn(
                            'order-last flex h-12 w-full items-stretch justify-center gap-1 md:order-none md:h-full md:w-auto',
                            headerAction &&
                                'lg:absolute lg:left-1/2 lg:-translate-x-1/2',
                        )}
                    >
                        <Link
                            className={cn(
                                'flex items-center border-b-2 border-transparent px-3 text-xs font-medium text-muted-foreground md:text-sm',
                                active === 'new' &&
                                    'border-primary font-semibold text-primary',
                            )}
                            href={home()}
                        >
                            New transfer
                        </Link>
                        <Link
                            className={cn(
                                'flex items-center border-b-2 border-transparent px-3 text-xs font-medium text-muted-foreground md:text-sm',
                                active === 'transfers' &&
                                    'border-primary font-semibold text-primary',
                            )}
                            href={transfers()}
                        >
                            My transfers
                        </Link>
                        {user?.is_admin && (
                            <Link
                                className={cn(
                                    'flex items-center border-b-2 border-transparent px-3 text-xs font-medium text-muted-foreground md:text-sm',
                                    active === 'teams' &&
                                        'border-primary font-semibold text-primary',
                                )}
                                href={teams()}
                            >
                                Teams
                            </Link>
                        )}
                    </nav>
                )}
                <div className="flex items-center gap-2 md:gap-4">
                    {headerAction}
                    {recipient && !recipientAccount ? (
                        <span className="text-xs text-muted-foreground md:text-sm">
                            Mediamax Communication
                        </span>
                    ) : (
                        user && (
                            <Popover>
                                <PopoverTrigger asChild>
                                    <button
                                        className="flex items-center gap-2.5 text-muted-foreground"
                                        aria-label="Account menu"
                                    >
                                        <span
                                            className={cn(
                                                'hidden md:inline',
                                                headerAction && 'md:hidden',
                                            )}
                                        >
                                            {user.name.split(' ')[0]}
                                        </span>
                                        <Avatar
                                            name={user.name}
                                            url={user.avatar_url}
                                        />
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    className="gap-3 p-5"
                                >
                                    <strong>{user.name}</strong>
                                    <span className="break-all text-muted-foreground">
                                        {user.email}
                                    </span>
                                    {user.email_verified_at && (
                                        <Link
                                            href={settings()}
                                            className="flex items-center gap-2"
                                        >
                                            <HugeiconsIcon
                                                icon={Settings01Icon}
                                                size={18}
                                                aria-hidden="true"
                                            />
                                            Settings
                                        </Link>
                                    )}
                                    <Link
                                        href={logout()}
                                        method="post"
                                        as="button"
                                        className="flex items-center gap-2 border-t pt-3"
                                    >
                                        <HugeiconsIcon
                                            icon={Logout01Icon}
                                            size={18}
                                            aria-hidden="true"
                                        />
                                        Sign out
                                    </Link>
                                </PopoverContent>
                            </Popover>
                        )
                    )}
                </div>
            </header>
            {children}
            {recipient && (
                <footer className="min-h-12 px-5 py-3 text-center text-xs text-muted-foreground">
                    Sent with Filemax, the file-sharing tool of Mediamax
                    Communication.
                </footer>
            )}
        </div>
    );
}
export function AuthLayout({
    title,
    description,
    children,
    footer,
}: {
    title: string;
    description: string;
    children: ReactNode;
    footer?: ReactNode;
}) {
    const status = usePage<SharedProps>().props.status;
    return (
        <main className="flex min-h-svh flex-col items-center justify-center gap-8 bg-muted px-5 py-8">
            <Brand large />
            <section className="flex w-full max-w-sm flex-col gap-6 rounded-xl border bg-background p-7 md:p-9">
                <div className="flex flex-col gap-1.5">
                    <h1 className="text-2xl">{title}</h1>
                    <p className="text-muted-foreground">{description}</p>
                </div>
                {status && (
                    <p
                        className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-slate-700"
                        role="status"
                    >
                        {status === 'verification-link-sent'
                            ? 'A new verification link has been sent to your email.'
                            : status}
                    </p>
                )}
                {children}
            </section>
            {footer && (
                <p className="max-w-sm text-center text-sm text-muted-foreground">
                    {footer}
                </p>
            )}
        </main>
    );
}
export function ErrorMessage({ children }: { children?: ReactNode }) {
    return children ? (
        <p className="text-sm wrap-anywhere text-destructive" role="alert">
            {children}
        </p>
    ) : null;
}
export function TeamBadges({
    teams,
    limit = Infinity,
    variant = 'default',
}: {
    teams: Team[];
    limit?: number;
    variant?: 'default' | 'muted';
}) {
    const badgeClassName = cn(
        'inline-flex max-w-full items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold wrap-anywhere text-primary',
        variant === 'muted' && 'border-border bg-muted text-slate-700',
    );
    return (
        <span className="inline-flex max-w-full flex-wrap items-center gap-1.5">
            {teams.slice(0, limit).map((team) => (
                <span className={badgeClassName} key={team.id}>
                    {team.name}
                </span>
            ))}
            {teams.length > limit && (
                <span
                    className={badgeClassName}
                    title={teams
                        .slice(limit)
                        .map((team) => team.name)
                        .join(', ')}
                >
                    +{teams.length - limit}
                </span>
            )}
        </span>
    );
}
export function TeamPicker({
    teams,
    selected,
    onChange,
    disabled = false,
}: {
    teams: Team[];
    selected: string[];
    onChange: (ids: string[]) => void;
    disabled?: boolean;
}) {
    const [query, setQuery] = useState('');
    const availableSelection = selected.filter((id) =>
        teams.some((team) => team.id === id),
    );
    const toggle = (id: string) =>
        onChange(
            availableSelection.includes(id)
                ? availableSelection.filter((value) => value !== id)
                : [...availableSelection, id],
        );
    return (
        <div className="flex min-w-0 flex-col gap-2.5">
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full flex-wrap justify-between gap-y-1 py-2 whitespace-normal"
                        aria-label="Choose teams"
                        disabled={disabled}
                    >
                        Choose teams
                        <span>
                            {availableSelection.length
                                ? `${availableSelection.length} selected`
                                : 'Select at least one'}
                        </span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-(--radix-popover-trigger-width) max-w-(--radix-popover-content-available-width) min-w-64 p-2"
                    align="start"
                >
                    <label className="sr-only" htmlFor="team-search">
                        Search teams
                    </label>
                    <input
                        id="team-search"
                        placeholder="Search teams…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <fieldset className="mt-2 max-h-56 overflow-auto">
                        <legend className="sr-only">Teams with access</legend>
                        {teams
                            .filter((team) =>
                                team.name
                                    .toLocaleLowerCase()
                                    .includes(query.toLocaleLowerCase()),
                            )
                            .map((team) => (
                                <label
                                    className="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-2.5 hover:bg-muted"
                                    key={team.id}
                                >
                                    <input
                                        type="checkbox"
                                        aria-label={team.name}
                                        checked={availableSelection.includes(
                                            team.id,
                                        )}
                                        onChange={() => toggle(team.id)}
                                    />
                                    <span className="min-w-0 flex-1 wrap-anywhere">
                                        {team.name}
                                    </span>
                                    <small className="text-muted-foreground">
                                        {team.users_count} members
                                    </small>
                                </label>
                            ))}
                    </fieldset>
                    {!teams.some((team) =>
                        team.name
                            .toLocaleLowerCase()
                            .includes(query.toLocaleLowerCase()),
                    ) && (
                        <p className="p-3 text-muted-foreground">
                            No teams found.
                        </p>
                    )}
                </PopoverContent>
            </Popover>
            <div className="inline-flex flex-wrap items-center gap-1.5">
                {teams
                    .filter((team) => availableSelection.includes(team.id))
                    .map((team) => (
                        <span
                            className="inline-flex max-w-full items-center gap-1.5 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-semibold wrap-anywhere text-primary"
                            key={team.id}
                        >
                            {team.name}
                            <button
                                type="button"
                                onClick={() => toggle(team.id)}
                                aria-label={`Remove ${team.name}`}
                                disabled={disabled}
                                className="inline-flex shrink-0 p-1"
                            >
                                <HugeiconsIcon
                                    icon={Cancel01Icon}
                                    size={14}
                                    aria-hidden="true"
                                />
                            </button>
                        </span>
                    ))}
            </div>
        </div>
    );
}
export function CopyLink({ url }: { url: string }) {
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState('');
    async function copy() {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            setError('');
        } catch {
            setError(
                'Copy is unavailable. Select the link and copy it manually.',
            );
        }
    }
    return (
        <div className="flex flex-col gap-2">
            <label className="text-sm font-semibold" htmlFor="share-link">
                Share this link
            </label>
            <div className="flex flex-wrap gap-2 md:flex-nowrap">
                <input
                    id="share-link"
                    readOnly
                    value={url}
                    className="basis-48 text-sm md:flex-1"
                    onFocus={(event) => event.target.select()}
                />
                <Button type="button" onClick={copy} className="px-4">
                    <HugeiconsIcon
                        icon={copied ? Tick02Icon : Copy01Icon}
                        size={18}
                        aria-hidden="true"
                    />
                    {copied ? 'Copied' : 'Copy link'}
                </Button>
            </div>
            <span role="status" className="sr-only">
                {copied ? 'Link copied to clipboard' : ''}
            </span>
            <ErrorMessage>{error}</ErrorMessage>
        </div>
    );
}
export function FileRow({
    name,
    size,
    children,
    variant = 'default',
}: {
    name: string;
    size: number;
    children?: ReactNode;
    variant?: 'default' | 'upload' | 'owner' | 'recipient';
}) {
    const extension = name.split('.').pop()?.toLowerCase() ?? '';
    const icon = ['mp4', 'mov', 'avi', 'webm', 'mkv'].includes(extension)
        ? Video01Icon
        : ['png', 'jpg', 'jpeg', 'gif', 'webp', 'psd', 'ai', 'svg'].includes(
                extension,
            )
          ? Image01Icon
          : ['zip', '7z', 'rar', 'tar', 'gz'].includes(extension)
            ? FileZipIcon
            : File01Icon;
    return (
        <div
            className={cn(
                'flex min-h-14 min-w-0 items-center gap-3 border-b border-slate-100',
                variant === 'upload' && 'border-0',
                variant === 'recipient' && 'min-h-15 md:min-h-14',
            )}
        >
            <span
                className={cn(
                    'inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground',
                    (variant === 'upload' || variant === 'owner') &&
                        'bg-blue-50 text-primary',
                )}
            >
                <HugeiconsIcon icon={icon} size={19} aria-hidden="true" />
            </span>
            <span
                className={cn(
                    'flex min-w-0 flex-1 flex-col',
                    variant === 'owner' &&
                        'md:flex-row md:items-center md:gap-3',
                )}
            >
                <span
                    className={cn(
                        'truncate font-medium',
                        variant === 'owner' && 'md:flex-1',
                    )}
                    title={name}
                >
                    {name}
                </span>
                <small
                    className={cn(
                        'text-xs text-muted-foreground',
                        variant === 'owner' && 'shrink-0 md:text-sm',
                    )}
                >
                    {bytes(size)}
                </small>
            </span>
            {children}
        </div>
    );
}
