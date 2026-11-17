import {
    Add01Icon,
    ArrowDown01Icon,
    ArrowLeft01Icon,
    ArrowRight01Icon,
    Copy01Icon,
    File01Icon,
    FileZipIcon,
    Image01Icon,
    LockKeyIcon,
    Logout01Icon,
    Settings01Icon,
    Tick02Icon,
    UserGroupIcon,
    UserIcon,
    Video01Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Link, usePage } from '@inertiajs/react';
import {
    useEffect,
    useRef,
    useState,
    type ComponentProps,
    type ReactNode,
} from 'react';
import { Avatar } from '@/components/avatar';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverHeader,
    PopoverTrigger,
} from '@/components/ui/popover';
import { bytes } from '@/lib/format';
import type { SharedProps } from '@/lib/types';
import { cn } from '@/lib/utils';
import { home, logout } from '@/routes';
import { settings } from '@/routes/account';
import { create as newDrop, index as drops } from '@/routes/drops';
import { members as organizationSettings } from '@/routes/organization';
import { switchMethod as switchOrganization } from '@/routes/organizations';
import { index as received } from '@/routes/received';
import { create as newSecret, index as secrets } from '@/routes/secrets';
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
    footer = 'Sent with Filemax.',
    organization = null,
}: {
    children: ReactNode;
    active?:
        | 'new'
        | 'transfers'
        | 'secrets'
        | 'new-secret'
        | 'drops'
        | 'new-drop'
        | 'organization'
        | 'account';
    recipient?: boolean;
    recipientAccount?: boolean;
    footer?: string;
    /** On recipient pages, the organization the link was sent from. Personal links pass none. */
    organization?: string | null;
}) {
    const user = usePage<SharedProps>().props.auth.user;
    // The header's primary action creates what the current section lists, and hides on its own form.
    const action =
        recipient ||
        active === 'new' ||
        active === 'new-secret' ||
        active === 'new-drop'
            ? null
            : active === 'secrets'
              ? { href: newSecret(), label: 'New secret' }
              : active === 'drops'
                ? { href: newDrop(), label: 'New Drop' }
                : { href: home(), label: 'New transfer' };
    const nav = useRef<HTMLElement>(null);
    // Below md the links can overflow narrow phones; keep the active one in view without scrolling the page.
    useEffect(() => {
        const link = nav.current?.querySelector('[aria-current="page"]');
        if (nav.current && link) {
            nav.current.scrollLeft +=
                link.getBoundingClientRect().right -
                nav.current.getBoundingClientRect().right;
        }
    }, [active]);
    const navLinkClass = (current: boolean) =>
        cn(
            'flex shrink-0 items-center border-b-2 border-transparent px-3 text-xs font-medium whitespace-nowrap text-muted-foreground focus-visible:-outline-offset-2 md:text-sm',
            current && 'border-primary font-semibold text-primary',
        );
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
                    !recipient && 'pt-3 md:pt-0',
                    recipient &&
                        !recipientAccount &&
                        'md:border-0 md:bg-transparent',
                )}
            >
                <Brand />
                {!recipient && user?.email_verified_at && (
                    <OrganizationSwitcher />
                )}
                {!recipient && (
                    <nav
                        ref={nav}
                        aria-label="Main navigation"
                        className="order-last -mx-5 flex h-12 w-[calc(100%+2.5rem)] [scrollbar-width:none] items-stretch justify-center-safe gap-1 overflow-x-auto px-5 md:order-none md:mx-0 md:h-full md:w-auto md:min-w-0 md:px-0 lg:absolute lg:left-1/2 lg:-translate-x-1/2 lg:overflow-visible [&::-webkit-scrollbar]:hidden"
                    >
                        <Link
                            className={navLinkClass(active === 'new')}
                            href={home()}
                            aria-current={active === 'new' ? 'page' : undefined}
                        >
                            New transfer
                        </Link>
                        <Link
                            className={navLinkClass(active === 'transfers')}
                            href={transfers()}
                            aria-current={
                                active === 'transfers' ? 'page' : undefined
                            }
                        >
                            My transfers
                        </Link>
                        <Link
                            className={navLinkClass(
                                active === 'secrets' || active === 'new-secret',
                            )}
                            href={secrets()}
                            aria-current={
                                active === 'secrets' || active === 'new-secret'
                                    ? 'page'
                                    : undefined
                            }
                        >
                            Secrets
                        </Link>
                        <Link
                            className={navLinkClass(
                                active === 'drops' || active === 'new-drop',
                            )}
                            href={drops()}
                            aria-current={
                                active === 'drops' || active === 'new-drop'
                                    ? 'page'
                                    : undefined
                            }
                        >
                            Drops
                        </Link>
                    </nav>
                )}
                <div className="flex items-center gap-2 md:gap-4">
                    {action && (
                        <Link
                            href={action.href}
                            aria-label={action.label}
                            className={buttonVariants({
                                size: 'icon-sm',
                                className: 'md:h-10 md:w-auto md:px-4',
                            })}
                        >
                            <HugeiconsIcon
                                icon={Add01Icon}
                                size={16}
                                aria-hidden="true"
                            />
                            <span className="hidden md:inline">
                                {action.label}
                            </span>
                        </Link>
                    )}
                    {recipient && !recipientAccount
                        ? organization && (
                              <span className="text-xs text-muted-foreground md:text-sm">
                                  {organization}
                              </span>
                          )
                        : user && (
                              <Popover>
                                  <PopoverTrigger
                                      className="flex items-center gap-2.5 text-muted-foreground"
                                      aria-label={`${user.name}, account menu`}
                                  >
                                      <span
                                          className={cn(
                                              'hidden md:inline',
                                              action && 'md:hidden',
                                          )}
                                      >
                                          {user.name.split(' ')[0]}
                                      </span>
                                      <Avatar
                                          name={user.name}
                                          url={user.avatar_url}
                                      />
                                  </PopoverTrigger>
                                  <PopoverContent
                                      align="end"
                                      className="w-54 gap-3 p-5"
                                  >
                                      <PopoverHeader>
                                          <strong>{user.name}</strong>
                                          <span
                                              className="truncate text-muted-foreground"
                                              title={user.email}
                                          >
                                              {user.email}
                                          </span>
                                      </PopoverHeader>
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
                          )}
                </div>
            </header>
            {children}
            {recipient && (
                <footer className="min-h-12 px-5 py-3 text-center text-xs text-muted-foreground">
                    {footer}
                </footer>
            )}
        </div>
    );
}
/** Switches My transfers between what you sent and what people sent you through your Drops. */
export function TransferTabs({
    current,
    newCount,
}: {
    current: 'sent' | 'received';
    newCount: number;
}) {
    const tabClass = (active: boolean) =>
        cn(
            '-mb-px inline-flex min-h-11 items-center gap-2 border-b-2 border-transparent px-3 text-sm font-medium text-muted-foreground transition-colors duration-160 ease-out hover:text-foreground',
            active &&
                'border-primary font-semibold text-primary hover:text-primary',
        );
    return (
        <nav aria-label="Sent or received" className="mb-5 flex gap-1 border-b">
            <Link
                href={transfers()}
                className={tabClass(current === 'sent')}
                aria-current={current === 'sent' ? 'page' : undefined}
            >
                Sent
            </Link>
            <Link
                href={received()}
                className={tabClass(current === 'received')}
                aria-current={current === 'received' ? 'page' : undefined}
            >
                Received
                {newCount > 0 && <NewBadge>{newCount} new</NewBadge>}
            </Link>
        </nav>
    );
}
export function NewBadge({ children = 'New' }: { children?: ReactNode }) {
    return (
        <span className="inline-flex h-5 shrink-0 items-center rounded-full border border-blue-200 bg-blue-50 px-2 text-xs font-semibold text-primary">
            {children}
        </span>
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
export function ErrorMessage({
    children,
    id,
}: {
    children?: ReactNode;
    id?: string;
}) {
    return children ? (
        <p
            id={id}
            className="text-sm wrap-anywhere text-destructive"
            role="alert"
        >
            {children}
        </p>
    ) : null;
}
export function PasswordBadge({ compact = false }: { compact?: boolean }) {
    return (
        <span
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-full bg-muted font-semibold text-slate-700',
                compact ? 'px-2.5 py-1 text-xs' : 'min-h-7 px-3 text-sm',
            )}
            title="Recipients need the password to open the link."
        >
            <HugeiconsIcon icon={LockKeyIcon} size={13} aria-hidden="true" />
            {compact ? 'Password' : 'Password protected'}
        </span>
    );
}
/** An organization's logo, or its first letter. Personal shows a person, since it is nobody but you. */
export function OrganizationMark({
    name,
    logoUrl = null,
    personal = false,
    className,
}: {
    name: string;
    logoUrl?: string | null;
    personal?: boolean;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-slate-900 text-sm font-bold text-white',
                personal && 'rounded-full bg-muted text-foreground',
                className,
            )}
        >
            {personal ? (
                <HugeiconsIcon icon={UserIcon} className="size-[56%]" />
            ) : logoUrl ? (
                <img src={logoUrl} alt="" className="size-full object-cover" />
            ) : (
                name.trim().charAt(0).toUpperCase()
            )}
        </span>
    );
}
function OrganizationSwitcher() {
    const { organizations } = usePage<SharedProps>().props.auth;
    const current = organizations.find((organization) => organization.current);

    if (!current) return null;

    return (
        <Popover>
            <PopoverTrigger
                aria-label={`Organization: ${current.name}. Switch organization`}
                className="mr-auto inline-flex h-9 shrink-0 items-center gap-2 rounded-md border border-input bg-background px-2.5 text-sm font-semibold text-foreground hover:bg-muted"
            >
                <OrganizationMark
                    name={current.name}
                    logoUrl={current.logo_url}
                    personal={current.personal}
                    className="size-5 rounded-[5px] text-[11px]"
                />
                <span className="hidden max-w-40 truncate md:inline">
                    {current.name}
                </span>
                <HugeiconsIcon
                    icon={ArrowDown01Icon}
                    size={14}
                    className="text-muted-foreground"
                    aria-hidden="true"
                />
            </PopoverTrigger>
            <PopoverContent
                align="start"
                sideOffset={8}
                className="w-[min(18.75rem,calc(100vw-1.5rem))] gap-1 p-2"
            >
                <span className="px-2 pt-1.5 pb-1 text-xs text-muted-foreground">
                    Send and see links as
                </span>
                {organizations.map((organization) => (
                    <Link
                        key={organization.id}
                        href={switchOrganization(organization.id)}
                        as="button"
                        aria-current={organization.current ? 'true' : undefined}
                        className={cn(
                            'flex w-full items-center gap-3 rounded-md p-2 text-left hover:bg-muted',
                            organization.current && 'bg-primary/5',
                        )}
                    >
                        <OrganizationMark
                            name={organization.name}
                            logoUrl={organization.logo_url}
                            personal={organization.personal}
                        />
                        <span className="flex min-w-0 flex-1 flex-col">
                            <strong className="truncate text-sm">
                                {organization.name}
                            </strong>
                            <span className="text-xs text-muted-foreground">
                                {organization.personal
                                    ? 'Only you'
                                    : `${organization.role === 'admin' ? 'Admin' : 'Member'} · ${organization.members_count} ${organization.members_count === 1 ? 'member' : 'members'}`}
                            </span>
                        </span>
                        {organization.current && (
                            <HugeiconsIcon
                                icon={Tick02Icon}
                                size={18}
                                className="text-primary"
                                aria-hidden="true"
                            />
                        )}
                    </Link>
                ))}
                {!current.personal && current.role === 'admin' && (
                    <Link
                        href={organizationSettings()}
                        className="mt-1 flex items-center gap-2.5 border-t px-2 pt-3 pb-2 text-foreground hover:text-foreground"
                    >
                        <HugeiconsIcon
                            icon={Settings01Icon}
                            size={18}
                            aria-hidden="true"
                        />
                        <span className="truncate">
                            {current.name} settings
                        </span>
                    </Link>
                )}
            </PopoverContent>
        </Popover>
    );
}
/** Marks a transfer only signed-in members of its organization can download. */
export function MembersBadge() {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground">
            <HugeiconsIcon icon={UserGroupIcon} size={13} aria-hidden="true" />
            Members only
        </span>
    );
}
export function CopyLink({
    url,
    noun = 'link',
    hint,
}: {
    url: string;
    noun?: 'link' | 'key';
    hint?: ReactNode;
}) {
    const [copied, setCopied] = useState(false);
    const [error, setError] = useState('');
    const id = noun === 'key' ? 'secret-key' : 'share-link';
    async function copy() {
        try {
            await navigator.clipboard.writeText(url);
            setCopied(true);
            setError('');
        } catch {
            setError(
                `Copy is unavailable. Select the ${noun} and copy it manually.`,
            );
        }
    }
    return (
        <div className="flex flex-col gap-2">
            <label className="text-sm font-semibold" htmlFor={id}>
                {noun === 'key' ? 'Key' : 'Share this link'}
            </label>
            <div className="flex flex-wrap gap-2 md:flex-nowrap">
                <input
                    id={id}
                    readOnly
                    value={url}
                    className={cn(
                        'basis-48 md:flex-1',
                        noun === 'key' ? 'font-mono tracking-wide' : 'text-sm',
                    )}
                    aria-describedby={hint ? `${id}-hint` : undefined}
                    onFocus={(event) => event.target.select()}
                />
                <Button
                    type="button"
                    variant={noun === 'key' ? 'outline' : 'default'}
                    onClick={copy}
                    className="px-4"
                >
                    <HugeiconsIcon
                        key={copied ? 'copied' : 'copy'}
                        icon={copied ? Tick02Icon : Copy01Icon}
                        size={18}
                        aria-hidden="true"
                        className={
                            copied
                                ? 'transition-[opacity,scale,filter] duration-150 ease-out starting:opacity-0 starting:blur-[2px] motion-safe:starting:scale-90'
                                : undefined
                        }
                    />
                    {copied
                        ? 'Copied'
                        : noun === 'key'
                          ? 'Copy key'
                          : 'Copy link'}
                </Button>
            </div>
            {hint && (
                <p id={`${id}-hint`} className="text-sm text-muted-foreground">
                    {hint}
                </p>
            )}
            <span role="status" className="sr-only">
                {copied
                    ? `${noun === 'key' ? 'Key' : 'Link'} copied to clipboard`
                    : ''}
            </span>
            <ErrorMessage>{error}</ErrorMessage>
        </div>
    );
}
export function RadioPill({
    children,
    ...props
}: Omit<ComponentProps<'input'>, 'type' | 'className'> & {
    children: ReactNode;
}) {
    return (
        <label className="inline-flex h-10 cursor-pointer items-center rounded-full border border-input px-3 has-checked:border-primary has-checked:bg-primary/5 has-checked:font-semibold has-checked:text-primary has-checked:ring-1 has-checked:ring-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary has-disabled:cursor-not-allowed">
            <input type="radio" className="sr-only" {...props} />
            {children}
        </label>
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
        : [
                'png',
                'jpg',
                'jpeg',
                'heic',
                'gif',
                'webp',
                'psd',
                'ai',
                'svg',
            ].includes(extension)
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

export type Paginated<T> = {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
};
const pageLinkClass = cn(
    buttonVariants({ variant: 'ghost', size: 'sm' }),
    'min-w-9 px-2.5 font-medium text-muted-foreground',
);
const pageBoundaryClass = cn(
    pageLinkClass,
    'min-h-11 gap-1.5 border-input px-3 text-foreground disabled:border-input md:min-h-9 md:border-transparent md:px-2.5 md:text-muted-foreground md:disabled:border-transparent',
);
export function Pagination({
    page,
    navigation,
}: {
    page: Paginated<unknown>;
    navigation: Omit<ComponentProps<typeof Link>, 'href'>;
}) {
    return (
        <nav
            aria-label="Pagination"
            className="mt-5 flex flex-wrap items-center justify-between gap-3"
        >
            <p className="hidden text-sm text-muted-foreground md:block">
                Showing{' '}
                <span className="font-semibold text-foreground tabular-nums">
                    {page.meta.from}–{page.meta.to}
                </span>{' '}
                of{' '}
                <span className="font-semibold text-foreground tabular-nums">
                    {page.meta.total}
                </span>
            </p>
            <div className="flex w-full items-center justify-between gap-2 md:w-auto md:flex-wrap md:justify-start md:gap-1">
                {page.links.prev ? (
                    <Link
                        {...navigation}
                        href={page.links.prev}
                        className={pageBoundaryClass}
                    >
                        <HugeiconsIcon
                            icon={ArrowLeft01Icon}
                            size={16}
                            aria-hidden="true"
                        />
                        Previous
                    </Link>
                ) : (
                    <Button
                        variant="ghost"
                        size="sm"
                        className={cn(
                            pageBoundaryClass,
                            'disabled:bg-transparent disabled:opacity-40',
                        )}
                        disabled
                    >
                        <HugeiconsIcon
                            icon={ArrowLeft01Icon}
                            size={16}
                            aria-hidden="true"
                        />
                        Previous
                    </Button>
                )}
                <p
                    className="text-sm whitespace-nowrap text-muted-foreground tabular-nums md:hidden"
                    aria-live="polite"
                >
                    Page{' '}
                    <span className="font-semibold text-foreground">
                        {page.meta.current_page}
                    </span>{' '}
                    of{' '}
                    <span className="font-semibold text-foreground">
                        {page.meta.last_page}
                    </span>
                </p>
                {page.meta.links.slice(1, -1).map((link, position) =>
                    link.url ? (
                        <Link
                            {...navigation}
                            key={position}
                            href={link.url}
                            aria-label={`Page ${link.label}`}
                            aria-current={link.active ? 'page' : undefined}
                            className={cn(
                                pageLinkClass,
                                'hidden md:inline-flex',
                                link.active &&
                                    'bg-foreground text-background hover:bg-foreground hover:text-background',
                            )}
                        >
                            {link.label}
                        </Link>
                    ) : (
                        <span
                            key={position}
                            className="hidden px-2 text-muted-foreground md:inline"
                            aria-label="More pages"
                        >
                            …
                        </span>
                    ),
                )}
                {page.links.next ? (
                    <Link
                        {...navigation}
                        href={page.links.next}
                        className={pageBoundaryClass}
                    >
                        Next
                        <HugeiconsIcon
                            icon={ArrowRight01Icon}
                            size={16}
                            aria-hidden="true"
                        />
                    </Link>
                ) : (
                    <Button
                        variant="ghost"
                        size="sm"
                        className={cn(
                            pageBoundaryClass,
                            'disabled:bg-transparent disabled:opacity-40',
                        )}
                        disabled
                    >
                        Next
                        <HugeiconsIcon
                            icon={ArrowRight01Icon}
                            size={16}
                            aria-hidden="true"
                        />
                    </Button>
                )}
            </div>
        </nav>
    );
}
