import { Logout01Icon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { initials } from '@/lib/format';
import type { SharedProps } from '@/lib/types';
import { home, logout } from '@/routes';
import { index as teams } from '@/routes/teams';

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
    active = 'home',
}: {
    children: ReactNode;
    active?: 'home' | 'teams';
}) {
    const user = usePage<SharedProps>().props.auth.user;
    return (
        <div className="app-shell">
            <header className="site-header">
                <Brand />
                <nav aria-label="Main navigation">
                    <Link
                        className={active === 'home' ? 'active' : ''}
                        href={home()}
                    >
                        Home
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
                <div className="site-header-actions">
                    {user && (
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
                    )}
                </div>
            </header>
            {children}
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
