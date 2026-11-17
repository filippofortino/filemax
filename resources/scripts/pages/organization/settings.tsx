import {
    Add01Icon,
    Globe02Icon,
    Settings01Icon,
    UserGroupIcon,
    UserIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { ErrorMessage, OrganizationMark, Shell } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { date } from '@/lib/format';
import type { SharedProps, User } from '@/lib/types';
import { cn } from '@/lib/utils';
import {
    domains as domainSettings,
    members as memberSettings,
    show as generalSettings,
    update as updateOrganization,
} from '@/routes/organization';
import {
    destroy as removeDomain,
    store as addDomain,
} from '@/routes/organization/domains';
import {
    destroy as cancelInvitation,
    resend as resendInvitation,
    store as sendInvitations,
} from '@/routes/organization/invitations';
import {
    destroy as removeMember,
    update as updateMember,
} from '@/routes/organization/members';

type Role = 'admin' | 'member';
type OrganizationDetails = {
    id: string;
    name: string;
    logo_url: string | null;
};
type Member = {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
    role: Role;
};
type Invitation = {
    id: string;
    email: string;
    role: Role;
    invited_by: string | null;
    expires_at: string;
    expired: boolean;
};
type Domain = { id: string; domain: string; members_count: number };

const sections = [
    {
        id: 'general',
        label: 'General',
        icon: Settings01Icon,
        href: generalSettings,
    },
    {
        id: 'members',
        label: 'Members',
        icon: UserGroupIcon,
        href: memberSettings,
    },
    {
        id: 'domains',
        label: 'Allowed domains',
        icon: Globe02Icon,
        href: domainSettings,
    },
] as const;

export default function OrganizationSettings({
    section,
    organization,
    members,
    invitations,
    domains,
}: {
    section: (typeof sections)[number]['id'];
    organization: OrganizationDetails;
    members: Member[];
    invitations: Invitation[];
    domains: Domain[];
}) {
    const { auth } = usePage<SharedProps>().props;
    const nav = useRef<HTMLElement>(null);
    const current = sections.find((item) => item.id === section) ?? sections[0];

    // Below md the tabs can overflow narrow phones; keep the active one in view without scrolling the page.
    useEffect(() => {
        const tab = nav.current?.querySelector('[aria-current="page"]');
        if (nav.current && tab) {
            nav.current.scrollLeft +=
                tab.getBoundingClientRect().right -
                nav.current.getBoundingClientRect().right;
        }
    }, [section]);

    return (
        <Shell active="organization">
            <Head title={`${current.label} · ${organization.name}`} />
            <main className="flex-1 bg-muted">
                <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 px-5 py-12 sm:px-8">
                    <div className="flex flex-col gap-2">
                        <h1 className="wrap-anywhere">{organization.name}</h1>
                        <p className="text-muted-foreground">
                            Organization settings. Only admins see this page.
                        </p>
                    </div>
                    <div className="flex flex-col gap-6 md:flex-row md:items-start md:gap-10">
                        <nav
                            ref={nav}
                            aria-label="Organization settings"
                            className="flex gap-1 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-border)] md:w-48 md:shrink-0 md:flex-col md:gap-0 md:shadow-[inset_1px_0_0_var(--color-border)]"
                        >
                            {sections.map((item) => (
                                <Link
                                    key={item.id}
                                    href={item.href()}
                                    aria-current={
                                        item === current ? 'page' : undefined
                                    }
                                    className={cn(
                                        'flex min-h-11 shrink-0 items-center gap-2.5 border-b-2 border-transparent px-3 text-sm font-medium whitespace-nowrap text-muted-foreground transition-colors duration-160 ease-out hover:text-foreground focus-visible:-outline-offset-2 md:border-b-0 md:border-l-2 md:px-4',
                                        item === current &&
                                            'border-primary font-semibold text-primary hover:text-primary',
                                    )}
                                >
                                    <HugeiconsIcon
                                        icon={item.icon}
                                        size={18}
                                        aria-hidden="true"
                                    />
                                    {item.label}
                                </Link>
                            ))}
                        </nav>
                        <div className="flex min-w-0 flex-1 flex-col gap-6">
                            {section === 'general' && auth.user && (
                                <General
                                    organization={organization}
                                    user={auth.user}
                                />
                            )}
                            {section === 'members' && auth.user && (
                                <>
                                    <Members
                                        organization={organization}
                                        members={members}
                                        user={auth.user}
                                    />
                                    <Invitations invitations={invitations} />
                                </>
                            )}
                            {section === 'domains' && (
                                <Domains
                                    organization={organization}
                                    domains={domains}
                                />
                            )}
                        </div>
                    </div>
                </div>
            </main>
        </Shell>
    );
}

function General({
    organization,
    user,
}: {
    organization: OrganizationDetails;
    user: User;
}) {
    const logoInput = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const form = useForm({
        name: organization.name,
        logo: null as File | null,
        remove_logo: false,
    });

    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview],
    );

    return (
        <section
            aria-labelledby="org-name-title"
            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
        >
            <div className="flex flex-col gap-1.5">
                <h2 id="org-name-title" className="text-xl">
                    Name and logo
                </h2>
                <p className="text-sm text-muted-foreground">
                    Shown next to the sender on every transfer, secret and Drop
                    sent from this organization.
                </p>
            </div>
            <form
                className="flex flex-col gap-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(updateOrganization.url(), {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset('logo', 'remove_logo');
                            form.setDefaults('name', form.data.name);
                            setPreview(null);
                            if (logoInput.current) logoInput.current.value = '';
                        },
                    });
                }}
            >
                <div className="flex flex-wrap items-center gap-5">
                    <OrganizationMark
                        name={form.data.name}
                        logoUrl={
                            preview ??
                            (form.data.remove_logo
                                ? null
                                : organization.logo_url)
                        }
                        className="size-16 rounded-[14px] text-[28px]"
                    />
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                        <div className="flex flex-wrap gap-2.5">
                            <input
                                ref={logoInput}
                                id="logo"
                                type="file"
                                accept="image/jpeg,image/png"
                                hidden
                                disabled={form.processing}
                                onChange={(event) => {
                                    const logo = event.target.files?.[0];
                                    if (!logo) return;
                                    form.setData((data) => ({
                                        ...data,
                                        logo,
                                        remove_logo: false,
                                    }));
                                    form.clearErrors('logo', 'remove_logo');
                                    setPreview(URL.createObjectURL(logo));
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                aria-describedby="logo-requirements"
                                onClick={() => logoInput.current?.click()}
                            >
                                Upload logo
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-muted-foreground"
                                disabled={
                                    form.processing ||
                                    (!preview &&
                                        (!organization.logo_url ||
                                            form.data.remove_logo))
                                }
                                onClick={() => {
                                    form.setData((data) => ({
                                        ...data,
                                        logo: null,
                                        remove_logo: true,
                                    }));
                                    form.clearErrors('logo', 'remove_logo');
                                    setPreview(null);
                                    if (logoInput.current)
                                        logoInput.current.value = '';
                                }}
                            >
                                Remove
                            </Button>
                        </div>
                        <p
                            id="logo-requirements"
                            className="text-sm text-muted-foreground"
                        >
                            JPG or PNG, at least 200×200 px. Without one we use
                            the first letter.
                        </p>
                        <ErrorMessage>{form.errors.logo}</ErrorMessage>
                        <ErrorMessage>{form.errors.remove_logo}</ErrorMessage>
                    </div>
                </div>
                <div className="flex flex-col gap-2">
                    <label className="text-sm font-semibold" htmlFor="org-name">
                        Organization name
                    </label>
                    <input
                        id="org-name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        maxLength={255}
                        required
                        aria-invalid={!!form.errors.name}
                    />
                    <ErrorMessage>{form.errors.name}</ErrorMessage>
                </div>
                <div className="flex items-center gap-3 rounded-lg bg-muted px-4 py-3">
                    <Avatar
                        name={user.name}
                        url={user.avatar_url}
                        className="size-9"
                    />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="text-xs text-muted-foreground">
                            What recipients see
                        </span>
                        <span className="text-sm wrap-anywhere">
                            <strong>{user.name}</strong> · {form.data.name}
                        </span>
                    </div>
                </div>
                <Button
                    type="submit"
                    className="self-start"
                    disabled={form.processing}
                >
                    {form.processing ? 'Saving…' : 'Save changes'}
                </Button>
            </form>
        </section>
    );
}

function Members({
    organization,
    members,
    user,
}: {
    organization: OrganizationDetails;
    members: Member[];
    user: User;
}) {
    const [removing, setRemoving] = useState<Member | null>(null);
    const [busy, setBusy] = useState(false);

    return (
        <section
            aria-labelledby="members-title"
            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-col gap-1.5">
                    <h2 id="members-title" className="text-xl">
                        Members
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {members.length}{' '}
                        {members.length === 1 ? 'member' : 'members'}. They can
                        open members-only links and send as {organization.name}.
                    </p>
                </div>
                <InviteDialog organization={organization} />
            </div>
            <div className="divide-y border-y">
                {members.map((member) => (
                    <div
                        key={member.id}
                        className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 py-4"
                    >
                        <div className="flex min-w-0 items-center gap-3.5">
                            <Avatar
                                name={member.name}
                                url={member.avatar_url}
                                className="size-9 border-transparent bg-muted font-semibold text-foreground"
                            />
                            <div className="min-w-0">
                                <strong className="text-sm">
                                    {member.name}
                                </strong>
                                <p className="text-sm break-all text-muted-foreground">
                                    {member.email}
                                </p>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-3">
                            {member.id === user.id ? (
                                <span className="text-sm text-muted-foreground">
                                    {member.role === 'admin'
                                        ? 'Admin'
                                        : 'Member'}{' '}
                                    · you
                                </span>
                            ) : (
                                <>
                                    <select
                                        aria-label={`Role of ${member.name}`}
                                        className="w-33"
                                        value={member.role}
                                        onChange={(event) =>
                                            router.patch(
                                                updateMember.url(member.id),
                                                {
                                                    role: event.target.value,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <option value="member">Member</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Remove ${member.name} from ${organization.name}`}
                                        onClick={() => setRemoving(member)}
                                    >
                                        Remove
                                    </Button>
                                </>
                            )}
                        </div>
                    </div>
                ))}
            </div>
            <Dialog
                open={removing !== null}
                onOpenChange={(open) => {
                    if (!open) setRemoving(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Remove {removing?.name} from {organization.name}?
                        </DialogTitle>
                        <DialogDescription>
                            They can no longer send as {organization.name} or
                            open its members-only links. The links they already
                            sent keep working.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => setRemoving(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={busy}
                            onClick={() => {
                                if (!removing) return;
                                router.delete(removeMember.url(removing.id), {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => {
                                        setBusy(false);
                                        setRemoving(null);
                                    },
                                });
                            }}
                        >
                            Remove member
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function InviteDialog({ organization }: { organization: OrganizationDetails }) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const form = useForm({ emails: [] as string[], role: 'member' as Role });
    const emails = [
        ...new Set(
            text
                .split(/[\s,;]+/)
                .map((email) => email.trim().toLowerCase())
                .filter(Boolean),
        ),
    ];

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    setText('');
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger render={<Button />}>
                <HugeiconsIcon icon={Add01Icon} size={16} aria-hidden="true" />
                Invite people
            </DialogTrigger>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        Invite people to {organization.name}
                    </DialogTitle>
                    <DialogDescription>
                        Each person gets an email with a link to join. It works
                        for 7 days, even while sign-up is closed.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex flex-col gap-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({ ...data, emails }));
                        form.post(sendInvitations.url(), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <div className="flex flex-col gap-2">
                        <label
                            className="text-sm font-semibold"
                            htmlFor="invite-emails"
                        >
                            Email addresses
                        </label>
                        <textarea
                            id="invite-emails"
                            rows={3}
                            placeholder="name@example.com, one per line or separated by commas"
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            required
                            aria-invalid={Object.keys(form.errors).length > 0}
                        />
                        <ErrorMessage>
                            {[...new Set(Object.values(form.errors))].join(' ')}
                        </ErrorMessage>
                    </div>
                    <fieldset>
                        <legend className="mb-2.5 text-sm font-semibold">
                            Role
                        </legend>
                        <div className="grid grid-cols-2 gap-3">
                            {(
                                [
                                    {
                                        value: 'member',
                                        label: 'Member',
                                        text: `Sends as ${organization.name} and opens members-only links.`,
                                    },
                                    {
                                        value: 'admin',
                                        label: 'Admin',
                                        text: 'Also manages members, domains and the name.',
                                    },
                                ] as const
                            ).map((option) => (
                                <label
                                    key={option.value}
                                    className="flex cursor-pointer flex-col gap-1 rounded-lg border border-input p-4 has-checked:border-primary has-checked:bg-primary/5 has-checked:ring-1 has-checked:ring-primary has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary"
                                >
                                    <span className="flex items-center justify-between">
                                        <strong className="text-base">
                                            {option.label}
                                        </strong>
                                        <input
                                            type="radio"
                                            name="invite-role"
                                            value={option.value}
                                            checked={
                                                form.data.role === option.value
                                            }
                                            onChange={() =>
                                                form.setData(
                                                    'role',
                                                    option.value,
                                                )
                                            }
                                        />
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {option.text}
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
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
                            disabled={form.processing || !emails.length}
                        >
                            {emails.length > 1
                                ? `Send ${emails.length} invites`
                                : 'Send invite'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Invitations({ invitations }: { invitations: Invitation[] }) {
    return (
        <section
            aria-labelledby="invites-title"
            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
        >
            <div className="flex flex-col gap-1.5">
                <h2 id="invites-title" className="text-xl">
                    Pending invites
                </h2>
                <p className="text-sm text-muted-foreground">
                    An invite link works for 7 days and only for the email it
                    was sent to.
                </p>
            </div>
            {invitations.length ? (
                <div className="divide-y border-y">
                    {invitations.map((invitation) => (
                        <div
                            key={invitation.id}
                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 py-4"
                        >
                            <div className="flex min-w-0 items-center gap-3.5">
                                <span
                                    aria-hidden="true"
                                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-full border border-dashed border-input text-muted-foreground"
                                >
                                    <HugeiconsIcon icon={UserIcon} size={18} />
                                </span>
                                <div className="min-w-0">
                                    <strong className="text-sm break-all">
                                        {invitation.email}
                                    </strong>
                                    <p className="text-sm text-muted-foreground">
                                        {[
                                            invitation.role === 'admin'
                                                ? 'Admin'
                                                : 'Member',
                                            invitation.invited_by &&
                                                `invited by ${invitation.invited_by}`,
                                            `${invitation.expired ? 'expired' : 'expires'} ${date(invitation.expires_at)}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    aria-label={`Resend invite to ${invitation.email}`}
                                    onClick={() =>
                                        router.post(
                                            resendInvitation.url(invitation.id),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Resend
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Cancel invite to ${invitation.email}`}
                                    onClick={() =>
                                        router.delete(
                                            cancelInvitation.url(invitation.id),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Cancel invite
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <p className="rounded-lg bg-muted px-4 py-3 text-sm text-muted-foreground">
                    No pending invites.
                </p>
            )}
        </section>
    );
}

function Domains({
    organization,
    domains,
}: {
    organization: OrganizationDetails;
    domains: Domain[];
}) {
    const form = useForm({ domain: '' });

    return (
        <section
            aria-labelledby="domains-title"
            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
        >
            <div className="flex flex-col gap-1.5">
                <h2 id="domains-title" className="text-xl">
                    Allowed domains
                </h2>
                <p className="text-sm text-muted-foreground">
                    Anyone who signs up and verifies an email on one of these
                    domains joins {organization.name} as a member, with no
                    invite.
                </p>
            </div>
            <form
                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(addDomain.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <div className="flex flex-1 flex-col gap-2">
                    <label htmlFor="domain" className="text-sm font-semibold">
                        Email domain
                    </label>
                    <input
                        id="domain"
                        name="domain"
                        placeholder="e.g. example.com"
                        maxLength={255}
                        autoComplete="off"
                        required
                        value={form.data.domain}
                        onChange={(event) =>
                            form.setData('domain', event.target.value)
                        }
                        aria-invalid={!!form.errors.domain}
                        aria-describedby="domain-error"
                    />
                </div>
                <Button type="submit" disabled={form.processing}>
                    Add domain
                </Button>
            </form>
            <ErrorMessage id="domain-error">{form.errors.domain}</ErrorMessage>
            {domains.length > 0 && (
                <div className="divide-y border-y">
                    {domains.map((domain) => (
                        <div
                            key={domain.id}
                            className="flex items-center justify-between gap-4 py-4"
                        >
                            <div className="flex min-w-0 items-center gap-3.5">
                                <span
                                    aria-hidden="true"
                                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-foreground"
                                >
                                    <HugeiconsIcon
                                        icon={Globe02Icon}
                                        size={18}
                                    />
                                </span>
                                <div className="min-w-0">
                                    <strong className="text-sm break-all">
                                        {domain.domain}
                                    </strong>
                                    <p className="text-sm text-muted-foreground">
                                        {domain.members_count === 0
                                            ? 'No member uses this domain yet'
                                            : `${domain.members_count} ${domain.members_count === 1 ? 'member uses' : 'members use'} this domain`}
                                    </p>
                                </div>
                            </div>
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={`Remove ${domain.domain}`}
                                onClick={() =>
                                    router.delete(removeDomain.url(domain.id), {
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Remove
                            </Button>
                        </div>
                    ))}
                </div>
            )}
            <p className="rounded-lg border border-blue-200 bg-accent px-4 py-3 text-sm text-slate-700">
                Removing a domain stops new people joining with it. Members who
                already joined stay until you remove them in Members.
            </p>
        </section>
    );
}
