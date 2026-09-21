import {
    Cancel01Icon,
    Copy01Icon,
    File01Icon,
    FileZipIcon,
    Image01Icon,
    Logout01Icon,
    Tick02Icon,
    Video01Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Link, usePage } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { bytes, initials } from '@/lib/format';
import type { SharedProps, Team } from '@/lib/types';
import { home, logout } from '@/routes';
import { index as teams } from '@/routes/teams';
import { index as transfers } from '@/routes/transfers';

export function Brand({ large = false }: { large?: boolean }) {
    return (
        <Link href={home()} className={`brand${large ? ' brand-large' : ''}`}>
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
    active?: 'new' | 'transfers' | 'teams';
    recipient?: boolean;
    recipientAccount?: boolean;
    headerAction?: ReactNode;
}) {
    const user = usePage<SharedProps>().props.auth.user;
    return (
        <div
            className={
                recipient ? 'app-shell recipient-shell dotted' : 'app-shell'
            }
        >
            <header className="site-header">
                <Brand />
                {!recipient && (
                    <nav aria-label="Main navigation">
                        <Link
                            className={active === 'new' ? 'active' : ''}
                            href={home()}
                        >
                            New transfer
                        </Link>
                        <Link
                            className={active === 'transfers' ? 'active' : ''}
                            href={transfers()}
                        >
                            My transfers
                        </Link>
                        {user?.is_admin && (
                            <Link
                                className={active === 'teams' ? 'active' : ''}
                                href={teams()}
                            >
                                Teams
                            </Link>
                        )}
                    </nav>
                )}
                <div className="site-header-actions">
                    {headerAction}
                    {recipient && !recipientAccount ? (
                        <span className="organization">
                            Mediamax Communication
                        </span>
                    ) : (
                        user && (
                            <Popover>
                                <PopoverTrigger asChild>
                                    <button
                                        className="account"
                                        aria-label="Account menu"
                                    >
                                        <span>{user.name.split(' ')[0]}</span>
                                        <span className="avatar">
                                            {initials(user.name)}
                                        </span>
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    className="account-popover"
                                >
                                    <strong>{user.name}</strong>
                                    <span className="muted break-all">
                                        {user.email}
                                    </span>
                                    <Link
                                        href={logout()}
                                        method="post"
                                        as="button"
                                        className="account-logout"
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
                <footer>
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
        <main className="auth-shell dotted">
            <Brand large />
            <section className="auth-card">
                <div className="flex flex-col gap-1.5">
                    <h1>{title}</h1>
                    <p className="muted">{description}</p>
                </div>
                {status && (
                    <p className="notice" role="status">
                        {status === 'verification-link-sent'
                            ? 'A new verification link has been sent to your email.'
                            : status}
                    </p>
                )}
                {children}
            </section>
            {footer && <p className="auth-footer">{footer}</p>}
        </main>
    );
}
export function ErrorMessage({ children }: { children?: ReactNode }) {
    return children ? (
        <p className="error-message" role="alert">
            {children}
        </p>
    ) : null;
}
export function TeamBadges({
    teams,
    limit = Infinity,
}: {
    teams: Team[];
    limit?: number;
}) {
    return (
        <span className="team-badges">
            {teams.slice(0, limit).map((team) => (
                <span className="team-badge" key={team.id}>
                    {team.name}
                </span>
            ))}
            {teams.length > limit && (
                <span
                    className="team-badge"
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
        <div className="team-picker">
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full justify-between"
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
                <PopoverContent className="team-options" align="start">
                    <label className="sr-only" htmlFor="team-search">
                        Search teams
                    </label>
                    <input
                        id="team-search"
                        placeholder="Search teams…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <fieldset>
                        <legend className="sr-only">Teams with access</legend>
                        {teams
                            .filter((team) =>
                                team.name
                                    .toLocaleLowerCase()
                                    .includes(query.toLocaleLowerCase()),
                            )
                            .map((team) => (
                                <label className="team-option" key={team.id}>
                                    <input
                                        type="checkbox"
                                        aria-label={team.name}
                                        checked={availableSelection.includes(
                                            team.id,
                                        )}
                                        onChange={() => toggle(team.id)}
                                    />
                                    <span>{team.name}</span>
                                    <small>{team.users_count} members</small>
                                </label>
                            ))}
                    </fieldset>
                    {!teams.some((team) =>
                        team.name
                            .toLocaleLowerCase()
                            .includes(query.toLocaleLowerCase()),
                    ) && <p className="muted p-3">No teams found.</p>}
                </PopoverContent>
            </Popover>
            <div className="team-badges">
                {teams
                    .filter((team) => availableSelection.includes(team.id))
                    .map((team) => (
                        <span className="team-badge" key={team.id}>
                            {team.name}
                            <button
                                type="button"
                                onClick={() => toggle(team.id)}
                                aria-label={`Remove ${team.name}`}
                                disabled={disabled}
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
            <label className="field-label" htmlFor="share-link">
                Share this link
            </label>
            <div className="copy-link">
                <input
                    id="share-link"
                    readOnly
                    value={url}
                    onFocus={(event) => event.target.select()}
                />
                <Button type="button" onClick={copy}>
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
}: {
    name: string;
    size: number;
    children?: ReactNode;
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
        <div className="file-row">
            <span className="file-icon">
                <HugeiconsIcon icon={icon} size={19} aria-hidden="true" />
            </span>
            <span className="file-info">
                <span className="file-name" title={name}>
                    {name}
                </span>
                <small>{bytes(size)}</small>
            </span>
            {children}
        </div>
    );
}
