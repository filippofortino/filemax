import {
    Add01Icon,
    ArrowDown01Icon,
    Download01Icon,
    Globe02Icon,
    PaintbrushIcon,
    QrCodeIcon,
    Settings01Icon,
    Tick02Icon,
    UserGroupIcon,
    UserIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { Avatar } from '@/components/avatar';
import {
    Brand,
    ErrorMessage,
    FileRow,
    OrganizationMark,
    RecipientFooter,
    RecipientOrganization,
    Shell,
} from '@/components/filemax';
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
import { contrastWithWhite, isHexColor } from '@/lib/color';
import { date } from '@/lib/format';
import type {
    Branding as RecipientBranding,
    SharedProps,
    User,
} from '@/lib/types';
import { cn } from '@/lib/utils';
import {
    branding as brandingSettings,
    domains as domainSettings,
    members as memberSettings,
    show as generalSettings,
    update as updateOrganization,
} from '@/routes/organization';
import { update as updateBranding } from '@/routes/organization/branding';
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
type BrandingSettings = {
    brand_color: string | null;
    welcome_message: string | null;
    footer_text: string | null;
    footer_url: string | null;
    wordmark_url: string | null;
};

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
    {
        id: 'branding',
        label: 'Branding',
        icon: PaintbrushIcon,
        href: brandingSettings,
    },
] as const;

export default function OrganizationSettings({
    section,
    organization,
    members,
    invitations,
    domains,
    branding,
}: {
    section: (typeof sections)[number]['id'];
    organization: OrganizationDetails;
    members: Member[];
    invitations: Invitation[];
    domains: Domain[];
    branding: BrandingSettings | null;
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
                <div className="mx-auto flex w-full max-w-6xl flex-col gap-8 px-5 py-12 sm:px-8">
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
                            {section === 'branding' &&
                                branding &&
                                auth.user && (
                                    <Branding
                                        organization={organization}
                                        branding={branding}
                                        user={auth.user}
                                    />
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
        organization_id: organization.id,
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

/** All pass 4.5:1 on white, so white button text and colored links stay readable. Filemax blue saves as no color. */
const brandPresets = [
    { name: 'Filemax blue', value: '', hex: '#2140E0' },
    { name: 'Violet', value: '#6D28D9', hex: '#6D28D9' },
    { name: 'Magenta', value: '#BE185D', hex: '#BE185D' },
    { name: 'Red', value: '#B91C1C', hex: '#B91C1C' },
    { name: 'Orange', value: '#C2410C', hex: '#C2410C' },
    { name: 'Green', value: '#15803D', hex: '#15803D' },
    { name: 'Teal', value: '#0F766E', hex: '#0F766E' },
    { name: 'Slate', value: '#0F172B', hex: '#0F172B' },
] as const;

const swatchClass =
    'inline-flex size-8 items-center justify-center rounded-full text-white shadow-[inset_0_0_0_1px_rgb(15_23_43/0.12)] transition-shadow duration-160 ease-out group-hover:shadow-[inset_0_0_0_1px_rgb(15_23_43/0.12),0_0_0_2px_white,0_0_0_4px_var(--color-slate-300)] group-has-checked:shadow-[inset_0_0_0_1px_rgb(15_23_43/0.12),0_0_0_2px_white,0_0_0_4px_var(--color-slate-900)]';
const swatchLabelClass =
    'group relative inline-flex size-11 cursor-pointer items-center justify-center rounded-full has-focus-visible:outline-2 has-focus-visible:outline-primary';

function Branding({
    organization,
    branding,
    user,
}: {
    organization: OrganizationDetails;
    branding: BrandingSettings;
    user: User;
}) {
    const saved = brandPresets.find(
        (preset) => preset.value === (branding.brand_color ?? ''),
    );
    const [choice, setChoice] = useState<string>(
        saved ? saved.value : 'custom',
    );
    const [hex, setHex] = useState(saved ? '' : (branding.brand_color ?? ''));
    const [welcome, setWelcome] = useState(branding.welcome_message ?? '');
    const [footerText, setFooterText] = useState(branding.footer_text ?? '');
    const [footerUrl, setFooterUrl] = useState(branding.footer_url ?? '');
    const [wordmarkPreview, setWordmarkPreview] = useState<string | null>(null);
    const [removeWordmark, setRemoveWordmark] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const wordmarkInput = useRef<HTMLInputElement>(null);

    useEffect(
        () => () => {
            if (wordmarkPreview) URL.revokeObjectURL(wordmarkPreview);
        },
        [wordmarkPreview],
    );

    const custom = choice === 'custom';
    const hexValid = isHexColor(hex);
    const ratio = hexValid ? contrastWithWhite(hex) : 0;
    const preset = brandPresets.find((item) => item.value === choice);
    const color = custom ? (hexValid ? hex : null) : choice || null;
    const urlValid = /^https:\/\/[^\s/]+\.\S+$/i.test(footerUrl.trim());
    const wordmarkUrl =
        wordmarkPreview ?? (removeWordmark ? null : branding.wordmark_url);
    const preview: RecipientBranding = {
        name: organization.name,
        logo_url: organization.logo_url,
        wordmark_url: wordmarkUrl,
        color,
        welcome_message: welcome.trim() || null,
        footer_text: footerText.trim() || null,
        footer_url: urlValid ? footerUrl.trim() : null,
    };

    function clearWordmarkInput() {
        setWordmarkPreview(null);
        if (wordmarkInput.current) wordmarkInput.current.value = '';
    }

    return (
        <div className="flex flex-col gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_304px] lg:items-start lg:gap-x-8">
            <aside
                aria-labelledby="preview-title"
                className="flex flex-col gap-3 rounded-xl border bg-background p-4 lg:sticky lg:top-6 lg:col-start-2 lg:row-start-1"
            >
                <div className="flex items-center justify-between gap-3">
                    <div className="flex flex-col gap-0.5">
                        <h2
                            id="preview-title"
                            className="font-sans text-base font-semibold tracking-normal"
                        >
                            Preview
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            What recipients see. Updates as you type.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        className="lg:hidden"
                        aria-expanded={previewOpen}
                        aria-controls="branding-preview"
                        onClick={() => setPreviewOpen(!previewOpen)}
                    >
                        {previewOpen ? 'Hide preview' : 'Show preview'}
                    </Button>
                </div>
                <div
                    id="branding-preview"
                    className={cn(
                        'overflow-hidden rounded-lg border',
                        !previewOpen && 'hidden lg:block',
                    )}
                >
                    <BrandingPreview branding={preview} user={user} />
                </div>
            </aside>
            <Form
                action={updateBranding()}
                options={{ preserveScroll: true }}
                onSuccess={() => {
                    clearWordmarkInput();
                    setRemoveWordmark(false);
                }}
                className="flex min-w-0 flex-col gap-6 lg:col-start-1 lg:row-start-1"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="organization_id"
                            value={organization.id}
                        />
                        <input
                            type="hidden"
                            name="brand_color"
                            value={custom ? hex : choice}
                        />
                        <input
                            type="hidden"
                            name="remove_wordmark"
                            value={removeWordmark ? '1' : '0'}
                        />
                        <section
                            aria-labelledby="look-title"
                            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
                        >
                            <div className="flex flex-col gap-1.5">
                                <h2 id="look-title" className="text-xl">
                                    Logo and color
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Recipients see these on every page a{' '}
                                    {organization.name} link opens: downloads,
                                    passwords, Drops and secrets.
                                </p>
                            </div>
                            <div className="flex flex-col gap-2">
                                <span className="text-sm font-semibold">
                                    Full logo
                                </span>
                                <div className="flex flex-wrap items-center gap-4">
                                    <div
                                        className={cn(
                                            'flex h-18 w-65 max-w-full shrink-0 items-center justify-center rounded-lg border bg-white p-3',
                                            !wordmarkUrl &&
                                                'border-dashed border-slate-400',
                                        )}
                                    >
                                        {wordmarkUrl ? (
                                            <img
                                                src={wordmarkUrl}
                                                alt={`${organization.name} full logo`}
                                                className="h-6 w-auto max-w-full object-contain"
                                            />
                                        ) : (
                                            <span className="text-sm text-muted-foreground">
                                                No full logo yet
                                            </span>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2.5">
                                        <input
                                            ref={wordmarkInput}
                                            type="file"
                                            name="wordmark"
                                            accept="image/jpeg,image/png"
                                            hidden
                                            disabled={processing}
                                            onChange={(event) => {
                                                const file =
                                                    event.target.files?.[0];
                                                if (!file) return;
                                                setRemoveWordmark(false);
                                                setWordmarkPreview(
                                                    URL.createObjectURL(file),
                                                );
                                            }}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={processing}
                                            aria-describedby="wordmark-hint"
                                            onClick={() =>
                                                wordmarkInput.current?.click()
                                            }
                                        >
                                            {wordmarkUrl
                                                ? 'Replace'
                                                : 'Upload full logo'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-muted-foreground"
                                            disabled={
                                                processing || !wordmarkUrl
                                            }
                                            onClick={() => {
                                                clearWordmarkInput();
                                                setRemoveWordmark(true);
                                            }}
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </div>
                                <p
                                    id="wordmark-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Your logo with the name in it, as a JPG or
                                    PNG at least 600 px wide. A PNG with a
                                    transparent background looks best. It
                                    replaces the icon and name at the top of
                                    recipient pages, up to 24 px tall.
                                </p>
                                <ErrorMessage>{errors.wordmark}</ErrorMessage>
                                <ErrorMessage>
                                    {errors.remove_wordmark}
                                </ErrorMessage>
                            </div>
                            <div className="flex items-start gap-3.5 rounded-lg bg-muted px-4 py-3">
                                <OrganizationMark
                                    name={organization.name}
                                    logoUrl={organization.logo_url}
                                    className="size-11 rounded-[10px] text-xl"
                                />
                                <div className="flex min-w-0 flex-1 flex-col items-start gap-0.5">
                                    <strong className="text-sm">
                                        Icon and name
                                    </strong>
                                    <span className="text-sm text-muted-foreground">
                                        {wordmarkUrl
                                            ? 'Used in the app, and on recipient pages when there’s no full logo.'
                                            : 'Shown top right on recipient pages, next to Filemax.'}
                                    </span>
                                    <Link
                                        href={generalSettings()}
                                        className="-mb-2.5 inline-flex min-h-11 items-center text-sm font-semibold"
                                    >
                                        Change in General
                                    </Link>
                                </div>
                            </div>
                            <fieldset className="flex min-w-0 flex-col gap-2.5">
                                <legend className="mb-1 text-sm font-semibold">
                                    Brand color
                                </legend>
                                <p
                                    id="brand-color-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Buttons, links and focus rings take this
                                    color.
                                </p>
                                <div
                                    role="radiogroup"
                                    aria-describedby="brand-color-hint"
                                    className="flex flex-wrap gap-1"
                                >
                                    {brandPresets.map((item) => (
                                        <label
                                            key={item.name}
                                            title={item.name}
                                            className={swatchLabelClass}
                                        >
                                            <input
                                                type="radio"
                                                name="brand_color_choice"
                                                value={item.value}
                                                checked={choice === item.value}
                                                onChange={() =>
                                                    setChoice(item.value)
                                                }
                                                aria-label={item.name}
                                                className="sr-only"
                                            />
                                            <span
                                                className={swatchClass}
                                                style={{ background: item.hex }}
                                            >
                                                {choice === item.value && (
                                                    <HugeiconsIcon
                                                        icon={Tick02Icon}
                                                        size={18}
                                                        strokeWidth={2.25}
                                                        aria-hidden="true"
                                                    />
                                                )}
                                            </span>
                                        </label>
                                    ))}
                                    <label
                                        title="Custom color"
                                        className={swatchLabelClass}
                                    >
                                        <input
                                            type="radio"
                                            name="brand_color_choice"
                                            value="custom"
                                            checked={custom}
                                            onChange={() => setChoice('custom')}
                                            aria-label="Custom color"
                                            className="sr-only"
                                        />
                                        <span
                                            className={cn(
                                                swatchClass,
                                                !custom &&
                                                    'bg-white text-muted-foreground shadow-[inset_0_0_0_1.5px_var(--color-slate-400)]',
                                            )}
                                            style={
                                                custom
                                                    ? {
                                                          background: hexValid
                                                              ? hex
                                                              : 'white',
                                                      }
                                                    : undefined
                                            }
                                        >
                                            <HugeiconsIcon
                                                icon={
                                                    custom
                                                        ? Tick02Icon
                                                        : Add01Icon
                                                }
                                                size={custom ? 18 : 16}
                                                strokeWidth={
                                                    custom ? 2.25 : 1.5
                                                }
                                                aria-hidden="true"
                                            />
                                        </span>
                                    </label>
                                </div>
                                <p className="text-sm">
                                    <span className="text-muted-foreground">
                                        Selected:
                                    </span>{' '}
                                    <strong>
                                        {custom
                                            ? hexValid
                                                ? `Custom, ${hex.toUpperCase()}`
                                                : 'Custom'
                                            : `${preset?.name}, ${preset?.hex}`}
                                    </strong>
                                </p>
                            </fieldset>
                            {custom && (
                                <div className="flex flex-col gap-2">
                                    <label
                                        htmlFor="brand-hex"
                                        className="text-sm font-semibold"
                                    >
                                        Hex code
                                    </label>
                                    <div className="flex items-center gap-2.5">
                                        <span
                                            className="relative inline-flex size-11 shrink-0 overflow-hidden rounded-md shadow-[inset_0_0_0_1px_rgb(15_23_43/0.12)] has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary"
                                            style={{
                                                background: hexValid
                                                    ? hex
                                                    : 'white',
                                            }}
                                        >
                                            <input
                                                type="color"
                                                aria-label="Pick a color"
                                                value={
                                                    hexValid
                                                        ? hex.toLowerCase()
                                                        : '#2140e0'
                                                }
                                                onChange={(event) =>
                                                    setHex(
                                                        event.target.value.toUpperCase(),
                                                    )
                                                }
                                                className="absolute inset-0 size-full cursor-pointer opacity-0"
                                            />
                                        </span>
                                        <input
                                            id="brand-hex"
                                            value={hex}
                                            onChange={(event) => {
                                                const value =
                                                    event.target.value.trim();
                                                setHex(
                                                    value &&
                                                        !value.startsWith('#')
                                                        ? `#${value}`
                                                        : value,
                                                );
                                            }}
                                            maxLength={7}
                                            autoComplete="off"
                                            spellCheck={false}
                                            placeholder="#2140E0"
                                            aria-invalid={
                                                !hexValid || ratio < 4.5
                                            }
                                            aria-describedby="brand-hex-hint"
                                            className="max-w-40 font-mono"
                                        />
                                    </div>
                                    {hexValid && ratio >= 4.5 && (
                                        <p
                                            id="brand-hex-hint"
                                            className="flex items-center gap-1.5 text-sm text-muted-foreground"
                                        >
                                            <HugeiconsIcon
                                                icon={Tick02Icon}
                                                size={16}
                                                aria-hidden="true"
                                            />
                                            {ratio.toFixed(1)}:1 on white.
                                            Buttons and links stay easy to read.
                                        </p>
                                    )}
                                    <ErrorMessage id="brand-hex-hint">
                                        {hexValid && ratio < 4.5
                                            ? `Too light: ${ratio.toFixed(1)}:1 on white. Links and button text need at least 4.5:1, so pick a darker shade.`
                                            : !hexValid &&
                                              'Enter six hex digits after the #, like #2140E0.'}
                                    </ErrorMessage>
                                </div>
                            )}
                            {!custom && (
                                <ErrorMessage>
                                    {errors.brand_color}
                                </ErrorMessage>
                            )}
                        </section>
                        <section
                            aria-labelledby="words-title"
                            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
                        >
                            <div className="flex flex-col gap-1.5">
                                <h2 id="words-title" className="text-xl">
                                    Welcome note and footer
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Leave a field empty to keep the Filemax
                                    default.
                                </p>
                            </div>
                            <div className="flex flex-col gap-2">
                                <div className="flex items-baseline justify-between gap-3">
                                    <label
                                        htmlFor="welcome"
                                        className="text-sm font-semibold"
                                    >
                                        Welcome note
                                    </label>
                                    <span
                                        className="text-xs text-muted-foreground"
                                        aria-live="polite"
                                    >
                                        {welcome.length.toLocaleString('en-GB')}{' '}
                                        / 1,000
                                    </span>
                                </div>
                                <textarea
                                    id="welcome"
                                    name="welcome_message"
                                    rows={4}
                                    maxLength={1000}
                                    placeholder="e.g. Hi! Here are your files from our studio."
                                    value={welcome}
                                    onChange={(event) =>
                                        setWelcome(event.target.value)
                                    }
                                    aria-invalid={!!errors.welcome_message}
                                    aria-describedby="welcome-hint"
                                />
                                <p
                                    id="welcome-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Shown on transfers sent without a message. A
                                    sender’s own message always wins.
                                </p>
                                <ErrorMessage>
                                    {errors.welcome_message}
                                </ErrorMessage>
                            </div>
                            <div className="flex flex-col gap-2">
                                <label
                                    htmlFor="footer-text"
                                    className="text-sm font-semibold"
                                >
                                    Footer line
                                </label>
                                <input
                                    id="footer-text"
                                    name="footer_text"
                                    maxLength={120}
                                    placeholder={`e.g. ${organization.name}, Pisa`}
                                    value={footerText}
                                    onChange={(event) =>
                                        setFooterText(event.target.value)
                                    }
                                    aria-invalid={!!errors.footer_text}
                                    aria-describedby="footer-text-hint"
                                />
                                <p
                                    id="footer-text-hint"
                                    className="text-sm text-muted-foreground"
                                >
                                    Up to 120 characters, above “Sent with
                                    Filemax”.
                                </p>
                                <ErrorMessage>
                                    {errors.footer_text}
                                </ErrorMessage>
                            </div>
                            <div className="flex flex-col gap-2">
                                <label
                                    htmlFor="footer-url"
                                    className="text-sm font-semibold"
                                >
                                    Website
                                </label>
                                <input
                                    id="footer-url"
                                    name="footer_url"
                                    type="url"
                                    inputMode="url"
                                    maxLength={255}
                                    autoComplete="url"
                                    spellCheck={false}
                                    placeholder="https://example.com"
                                    value={footerUrl}
                                    onChange={(event) =>
                                        setFooterUrl(event.target.value)
                                    }
                                    aria-invalid={
                                        !!errors.footer_url ||
                                        (footerUrl.trim() !== '' && !urlValid)
                                    }
                                    aria-describedby="footer-url-hint"
                                />
                                {footerUrl.trim() !== '' && !urlValid ? (
                                    <ErrorMessage id="footer-url-hint">
                                        Use a full address that starts with
                                        https://, like https://example.com.
                                    </ErrorMessage>
                                ) : (
                                    <p
                                        id="footer-url-hint"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Shown as a link in the footer.
                                    </p>
                                )}
                                <ErrorMessage>{errors.footer_url}</ErrorMessage>
                            </div>
                        </section>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <Button
                                type="submit"
                                disabled={
                                    processing ||
                                    (custom && (!hexValid || ratio < 4.5))
                                }
                            >
                                {processing ? 'Saving…' : 'Save changes'}
                            </Button>
                            <p className="text-sm text-muted-foreground">
                                Applies to every {organization.name} link,
                                including ones already sent.
                            </p>
                        </div>
                    </>
                )}
            </Form>
        </div>
    );
}

/** A small, live copy of the download page in the organization's branding. */
function BrandingPreview({
    branding,
    user,
}: {
    branding: RecipientBranding;
    user: User;
}) {
    return (
        <div
            inert
            data-brand={branding.color ? '' : undefined}
            style={
                branding.color
                    ? ({ '--brand': branding.color } as CSSProperties)
                    : undefined
            }
            className="flex [zoom:0.54] flex-col bg-muted"
        >
            <div className="flex min-h-16 items-center justify-between gap-4 px-8">
                <Brand />
                <RecipientOrganization branding={branding} />
            </div>
            <div className="px-6 pb-2">
                <section className="flex flex-col gap-6 rounded-xl border bg-background p-8">
                    <div className="flex flex-col gap-3">
                        <div className="flex items-center gap-2.5 text-sm leading-snug text-muted-foreground">
                            <Avatar
                                name={user.name}
                                url={user.avatar_url}
                                className="size-9 border-transparent bg-muted text-sm font-semibold text-foreground"
                            />
                            <span>
                                <strong className="text-foreground">
                                    {user.name}
                                </strong>{' '}
                                · {branding.name}
                            </span>
                        </div>
                        <h3 className="text-3xl leading-tight">
                            Final campaign files
                        </h3>
                        {branding.welcome_message && (
                            <p className="text-base wrap-anywhere whitespace-pre-wrap text-muted-foreground">
                                {branding.welcome_message}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-col gap-1">
                        <Button size="lg" className="w-full">
                            <HugeiconsIcon
                                icon={Download01Icon}
                                size={20}
                                aria-hidden="true"
                            />
                            Download all · 1.68 GB
                        </Button>
                        <div className="flex items-center justify-center gap-1 text-sm text-muted-foreground">
                            Need it on your phone?
                            <Button variant="link" className="gap-1.5 px-1">
                                <HugeiconsIcon
                                    icon={QrCodeIcon}
                                    size={16}
                                    strokeWidth={1.5}
                                    aria-hidden="true"
                                />
                                Show QR code
                            </Button>
                        </div>
                    </div>
                    <ul className="border-y border-border">
                        {[
                            { name: 'Campaign_Spot_30s.mp4', size: 1200000000 },
                            { name: 'Key_Visual.psd', size: 482100000 },
                        ].map((file) => (
                            <li key={file.name}>
                                <FileRow
                                    name={file.name}
                                    size={file.size}
                                    variant="recipient"
                                >
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label={`Download ${file.name}`}
                                    >
                                        <HugeiconsIcon
                                            icon={Download01Icon}
                                            size={20}
                                            aria-hidden="true"
                                        />
                                    </Button>
                                </FileRow>
                            </li>
                        ))}
                    </ul>
                </section>
            </div>
            <RecipientFooter branding={branding} />
        </div>
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
                                    <span className="relative inline-flex">
                                        <select
                                            aria-label={`Role of ${member.name}`}
                                            className="w-33 appearance-none pr-9"
                                            value={member.role}
                                            onChange={(event) =>
                                                router.patch(
                                                    updateMember.url(member.id),
                                                    {
                                                        organization_id:
                                                            organization.id,
                                                        role: event.target
                                                            .value,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <option value="member">
                                                Member
                                            </option>
                                            <option value="admin">Admin</option>
                                        </select>
                                        <HugeiconsIcon
                                            icon={ArrowDown01Icon}
                                            size={16}
                                            className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </span>
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
                            open its members-only links. Their Drops in this
                            organization close immediately, including uploads in
                            progress. Transfers they already sent keep working.
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
                                    data: { organization_id: organization.id },
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
    const form = useForm({
        organization_id: organization.id,
        emails: [] as string[],
        role: 'member' as Role,
    });
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
    const form = useForm({ organization_id: organization.id, domain: '' });

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
