import { UserGroupIcon, UserIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import switchAccount from '@/actions/App/Http/Controllers/SwitchAccountController';
import {
    AuthLayout,
    Brand,
    ErrorMessage,
    OrganizationMark,
} from '@/components/filemax';
import { Button, buttonVariants } from '@/components/ui/button';
import { passwordHint } from '@/lib/format';
import type { SharedProps } from '@/lib/types';
import { home, login } from '@/routes';

type Invitation = {
    return_path: string;
    accept_url: string;
    register_url: string;
    email: string;
    role: 'admin' | 'member';
    organization: { name: string; logo_url: string | null };
    inviter: string | null;
    state: 'register' | 'join' | 'wrong_account';
};

export default function AcceptInvitation({
    invitation,
}: {
    invitation: Invitation | null;
}) {
    const { passwordRequirements } = usePage<SharedProps>().props;

    if (!invitation)
        return (
            <AuthLayout
                title="This invite no longer works"
                description="It expired, was cancelled or was already used. Ask an admin of the organization to send a new one."
                footer={<Link href={home()}>Go to Filemax</Link>}
            >
                <Head title="Invite unavailable" />
            </AuthLayout>
        );

    const { organization } = invitation;
    const invitedBy = invitation.inviter
        ? `${invitation.inviter} invited you`
        : 'You’ve been invited';
    const switchLink = (label: string) => (
        <Link
            href={switchAccount()}
            method="post"
            data={{ return_to: invitation.return_path }}
            as="button"
            className="font-semibold text-primary"
        >
            {label}
        </Link>
    );

    return (
        <main className="flex min-h-svh flex-col items-center justify-center gap-8 bg-muted px-5 py-8">
            <Head title={`Join ${organization.name}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <Brand large />
            <section className="flex w-full max-w-sm flex-col gap-6 rounded-xl border bg-background p-7 md:p-9">
                <div className="flex flex-col gap-3">
                    <OrganizationMark
                        name={organization.name}
                        logoUrl={organization.logo_url}
                        className="size-11 rounded-[10px] text-xl"
                    />
                    <div className="flex flex-col gap-1.5">
                        <h1 className="text-2xl wrap-anywhere">
                            Join {organization.name}
                            {invitation.state === 'join' && '?'}
                        </h1>
                        <p className="text-muted-foreground">
                            {invitation.state === 'register' &&
                                `${invitedBy}. Create your account to join.`}
                            {invitation.state === 'join' && (
                                <>
                                    {invitedBy} as{' '}
                                    {invitation.role === 'admin'
                                        ? 'an admin'
                                        : 'a member'}
                                    . You’re signed in as{' '}
                                    <span className="break-all">
                                        {invitation.email}
                                    </span>
                                    .
                                </>
                            )}
                            {invitation.state === 'wrong_account' && (
                                <>
                                    This invite is for{' '}
                                    <span className="break-all">
                                        {invitation.email}
                                    </span>
                                    , which isn’t the account you’re signed in
                                    with.
                                </>
                            )}
                        </p>
                    </div>
                </div>
                {invitation.state === 'register' && (
                    <Form
                        action={invitation.register_url}
                        method="post"
                        className="flex flex-col gap-5"
                        resetOnSuccess={['password', 'password_confirmation']}
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex flex-col gap-4">
                                    <div className="flex flex-col gap-2">
                                        <label
                                            className="text-sm font-semibold"
                                            htmlFor="name"
                                        >
                                            Full name
                                        </label>
                                        <input
                                            id="name"
                                            name="name"
                                            autoComplete="name"
                                            required
                                            maxLength={255}
                                            aria-invalid={!!errors.name}
                                        />
                                        <ErrorMessage>
                                            {errors.name}
                                        </ErrorMessage>
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <label
                                            className="text-sm font-semibold"
                                            htmlFor="email"
                                        >
                                            Email{' '}
                                            <span className="font-normal text-muted-foreground">
                                                (from your invite)
                                            </span>
                                        </label>
                                        <input
                                            id="email"
                                            type="email"
                                            readOnly
                                            value={invitation.email}
                                            className="border-border bg-muted text-muted-foreground"
                                        />
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <label
                                            className="text-sm font-semibold"
                                            htmlFor="password"
                                        >
                                            Password
                                        </label>
                                        <input
                                            id="password"
                                            name="password"
                                            type="password"
                                            autoComplete="new-password"
                                            minLength={passwordRequirements.min}
                                            required
                                            aria-invalid={!!errors.password}
                                            aria-describedby="password-requirements"
                                        />
                                        <p
                                            id="password-requirements"
                                            className="text-sm text-muted-foreground"
                                        >
                                            {passwordHint(passwordRequirements)}
                                        </p>
                                        <ErrorMessage>
                                            {errors.password}
                                        </ErrorMessage>
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        <label
                                            className="text-sm font-semibold"
                                            htmlFor="password_confirmation"
                                        >
                                            Confirm password
                                        </label>
                                        <input
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            type="password"
                                            autoComplete="new-password"
                                            minLength={passwordRequirements.min}
                                            required
                                        />
                                    </div>
                                </div>
                                <Button
                                    type="submit"
                                    className="w-full"
                                    size="lg"
                                    disabled={processing}
                                >
                                    {processing
                                        ? 'Creating account…'
                                        : 'Create account and join'}
                                </Button>
                            </>
                        )}
                    </Form>
                )}
                {invitation.state === 'join' && (
                    <>
                        <ul className="flex flex-col gap-3">
                            <li className="flex items-center gap-3 text-sm">
                                <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-slate-700">
                                    <HugeiconsIcon
                                        icon={UserGroupIcon}
                                        size={16}
                                        aria-hidden="true"
                                    />
                                </span>
                                <span>
                                    Send as {organization.name} and open its
                                    members-only links.
                                </span>
                            </li>
                            <li className="flex items-center gap-3 text-sm">
                                <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-slate-700">
                                    <HugeiconsIcon
                                        icon={UserIcon}
                                        size={16}
                                        aria-hidden="true"
                                    />
                                </span>
                                <span>
                                    Personal stays yours. Switch between the two
                                    in the header.
                                </span>
                            </li>
                        </ul>
                        <div className="flex flex-col gap-2.5">
                            <Link
                                href={invitation.accept_url}
                                method="post"
                                as="button"
                                className={buttonVariants({
                                    size: 'lg',
                                    className: 'w-full',
                                })}
                            >
                                Join {organization.name}
                            </Link>
                            <Link
                                href={home()}
                                className={buttonVariants({
                                    variant: 'outline',
                                    className: 'w-full',
                                })}
                            >
                                Not now
                            </Link>
                        </div>
                    </>
                )}
                {invitation.state === 'wrong_account' && (
                    <Link
                        href={switchAccount()}
                        method="post"
                        data={{ return_to: invitation.return_path }}
                        as="button"
                        className={buttonVariants({
                            size: 'lg',
                            className: 'w-full',
                        })}
                    >
                        Switch account
                    </Link>
                )}
            </section>
            <p className="max-w-sm text-center text-sm text-muted-foreground">
                {invitation.state === 'register' && (
                    <>
                        Already have an account with this email?{' '}
                        <Link href={login()}>Sign in</Link>
                    </>
                )}
                {invitation.state === 'join' && (
                    <>
                        Invited on a different email?{' '}
                        {switchLink('Switch account')}
                    </>
                )}
            </p>
        </main>
    );
}
