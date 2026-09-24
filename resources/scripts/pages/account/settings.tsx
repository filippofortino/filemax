import { Key01Icon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, router, useForm, usePage } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { ErrorMessage, Shell } from '@/components/filemax';
import { PasskeyFeedback } from '@/components/passkey-button';
import { Button } from '@/components/ui/button';
import { date, passwordHint } from '@/lib/format';
import type { SharedProps, User } from '@/lib/types';
import { destroy, registrationOptions, store } from '@/routes/passkey';
import { confirm as confirmPassword } from '@/routes/password';
import { update as updatePassword } from '@/routes/user-password';
import { update as updateProfile } from '@/routes/user-profile-information';

type Passkey = {
    id: number;
    name: string;
    created_at: string;
    last_used_at: string | null;
};

export default function Settings({ passkeys }: { passkeys: Passkey[] }) {
    const { auth, passwordRequirements } = usePage<SharedProps>().props;

    return (
        <Shell active="account">
            <Head title="Settings" />
            <main className="flex-1 bg-muted">
                <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-5 py-12 sm:px-8">
                    <div className="flex flex-col gap-2">
                        <h1>Settings</h1>
                        <p className="text-muted-foreground">
                            Your profile, your password, and the devices you
                            sign in with.
                        </p>
                    </div>
                    {auth.user && <Profile user={auth.user} />}
                    <section
                        aria-labelledby="password-title"
                        className="flex flex-col gap-5 rounded-xl border bg-background p-6"
                    >
                        <div className="flex flex-col gap-1.5">
                            <h2 id="password-title" className="text-xl">
                                Password
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Used when you sign in without a passkey.
                            </p>
                        </div>
                        <Form
                            id="password-form"
                            action={updatePassword()}
                            errorBag="updatePassword"
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="flex flex-col gap-5"
                        >
                            {({ errors, processing, recentlySuccessful }) => (
                                <>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div className="flex flex-col gap-2">
                                            <label
                                                htmlFor="password-current"
                                                className="text-sm font-semibold"
                                            >
                                                Current password
                                            </label>
                                            <input
                                                id="password-current"
                                                name="current_password"
                                                type="password"
                                                autoComplete="current-password"
                                                required
                                                aria-invalid={
                                                    !!errors.current_password
                                                }
                                            />
                                            <ErrorMessage>
                                                {errors.current_password}
                                            </ErrorMessage>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div className="flex flex-col gap-2">
                                            <label
                                                htmlFor="password-new"
                                                className="text-sm font-semibold"
                                            >
                                                New password
                                            </label>
                                            <input
                                                id="password-new"
                                                name="password"
                                                type="password"
                                                autoComplete="new-password"
                                                minLength={
                                                    passwordRequirements.min
                                                }
                                                required
                                                aria-describedby="password-requirements"
                                                aria-invalid={!!errors.password}
                                            />
                                            <ErrorMessage>
                                                {errors.password}
                                            </ErrorMessage>
                                        </div>
                                        <div className="flex flex-col gap-2">
                                            <label
                                                htmlFor="password-repeat"
                                                className="text-sm font-semibold"
                                            >
                                                Repeat new password
                                            </label>
                                            <input
                                                id="password-repeat"
                                                name="password_confirmation"
                                                type="password"
                                                autoComplete="new-password"
                                                minLength={
                                                    passwordRequirements.min
                                                }
                                                required
                                                aria-invalid={
                                                    !!errors.password_confirmation
                                                }
                                            />
                                            <ErrorMessage>
                                                {errors.password_confirmation}
                                            </ErrorMessage>
                                        </div>
                                    </div>
                                    <p
                                        id="password-requirements"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {passwordHint(passwordRequirements)}{' '}
                                        Changing it keeps your passkeys and
                                        signs you out everywhere else.
                                    </p>
                                    <Button
                                        className="self-start"
                                        disabled={processing}
                                    >
                                        {processing
                                            ? 'Updating…'
                                            : 'Update password'}
                                    </Button>
                                    {recentlySuccessful && (
                                        <p
                                            role="status"
                                            className="text-sm text-muted-foreground"
                                        >
                                            Password updated.
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    </section>
                    <Passkeys passkeys={passkeys} />
                </div>
            </main>
        </Shell>
    );
}

function Profile({ user }: { user: User }) {
    const photoInput = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const profile = useForm({
        _method: 'PUT',
        name: user.name,
        avatar: null as File | null,
        remove_avatar: false,
    });

    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview],
    );

    return (
        <section
            aria-labelledby="profile-title"
            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
        >
            <div className="flex flex-col gap-1.5">
                <h2 id="profile-title" className="text-xl">
                    Profile
                </h2>
                <p className="text-sm text-muted-foreground">
                    This is the name and picture people see on the transfers you
                    send.
                </p>
            </div>
            <form
                id="profile-form"
                className="flex flex-col gap-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    profile.post(updateProfile.url(), {
                        errorBag: 'updateProfileInformation',
                        preserveScroll: true,
                        onSuccess: () => {
                            profile.reset('avatar', 'remove_avatar');
                            profile.setDefaults('name', profile.data.name);
                            setPreview(null);
                            if (photoInput.current)
                                photoInput.current.value = '';
                        },
                    });
                }}
            >
                <div className="flex flex-wrap items-center gap-5">
                    <Avatar
                        name={profile.data.name}
                        url={
                            preview ??
                            (profile.data.remove_avatar
                                ? null
                                : user.avatar_url)
                        }
                        className="size-16 text-xl"
                    />
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                        <div className="flex flex-wrap gap-2.5">
                            <input
                                ref={photoInput}
                                id="avatar"
                                type="file"
                                accept="image/jpeg,image/png"
                                className="sr-only"
                                aria-label="Profile photo"
                                aria-describedby="avatar-requirements"
                                aria-invalid={!!profile.errors.avatar}
                                disabled={profile.processing}
                                onChange={(event) => {
                                    const avatar = event.target.files?.[0];
                                    if (!avatar) return;
                                    profile.setData((data) => ({
                                        ...data,
                                        avatar,
                                        remove_avatar: false,
                                    }));
                                    profile.clearErrors(
                                        'avatar',
                                        'remove_avatar',
                                    );
                                    setPreview(URL.createObjectURL(avatar));
                                }}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={profile.processing}
                                onClick={() => photoInput.current?.click()}
                            >
                                Upload photo
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                disabled={
                                    profile.processing ||
                                    (!preview &&
                                        (!user.avatar_url ||
                                            profile.data.remove_avatar))
                                }
                                onClick={() => {
                                    profile.setData((data) => ({
                                        ...data,
                                        avatar: null,
                                        remove_avatar: true,
                                    }));
                                    profile.clearErrors(
                                        'avatar',
                                        'remove_avatar',
                                    );
                                    setPreview(null);
                                    if (photoInput.current)
                                        photoInput.current.value = '';
                                }}
                            >
                                Remove
                            </Button>
                        </div>
                        <p
                            id="avatar-requirements"
                            className="text-sm text-muted-foreground"
                        >
                            JPG or PNG, up to 5 MB and 200–4096 px per side.
                            Without one we use your initials.
                        </p>
                        <ErrorMessage>{profile.errors.avatar}</ErrorMessage>
                        <ErrorMessage>
                            {profile.errors.remove_avatar}
                        </ErrorMessage>
                    </div>
                </div>
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-2">
                        <label
                            htmlFor="full-name"
                            className="text-sm font-semibold"
                        >
                            Full name
                        </label>
                        <input
                            id="full-name"
                            name="name"
                            value={profile.data.name}
                            onChange={(event) =>
                                profile.setData('name', event.target.value)
                            }
                            autoComplete="name"
                            maxLength={255}
                            required
                            aria-invalid={!!profile.errors.name}
                        />
                        <ErrorMessage>{profile.errors.name}</ErrorMessage>
                    </div>
                    <div className="flex flex-col gap-2">
                        <label
                            htmlFor="account-email"
                            className="text-sm font-semibold"
                        >
                            Email{' '}
                            <span className="font-normal text-muted-foreground">
                                (cannot be changed)
                            </span>
                        </label>
                        <input
                            id="account-email"
                            type="email"
                            readOnly
                            value={user.email}
                            className="border-border bg-muted text-muted-foreground"
                        />
                    </div>
                </div>
                <Button className="self-start" disabled={profile.processing}>
                    {profile.processing ? 'Saving…' : 'Save profile'}
                </Button>
                {profile.recentlySuccessful && (
                    <p role="status" className="text-sm text-muted-foreground">
                        Profile saved.
                    </p>
                )}
            </form>
        </section>
    );
}

function Passkeys({ passkeys }: { passkeys: Passkey[] }) {
    const { status } = usePage<SharedProps>().props;
    const [name, setName] = useState('');
    const [registered, setRegistered] = useState(false);
    const { register, isLoading, isSupported, error } = usePasskeyRegister({
        routes: { options: registrationOptions.url(), submit: store.url() },
        onSuccess: () => {
            setName('');
            setRegistered(true);
            router.reload();
        },
        onError: (error) => {
            if (error.message === 'Password confirmation required.') {
                router.visit(confirmPassword());
            }
        },
    });

    return (
        <section
            aria-labelledby="passkeys-title"
            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
        >
            <div className="flex flex-col gap-1.5">
                <h2 id="passkeys-title" className="text-xl">
                    Passkeys
                </h2>
                <p className="text-sm text-muted-foreground">
                    Sign in with your fingerprint, face, or device lock. Your
                    password stays available. Give each key a name you’ll
                    recognize, such as “Work MacBook”.
                </p>
            </div>
            {(status === 'passkey-deleted' || registered) && (
                <p
                    className="rounded-lg border border-blue-200 bg-accent px-4 py-3 text-slate-700"
                    role="status"
                >
                    {status === 'passkey-deleted'
                        ? 'Passkey removed.'
                        : 'Passkey added. You can now use it to sign in.'}
                </p>
            )}
            <form
                id="passkey-form"
                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    setRegistered(false);
                    void register(name.trim());
                }}
            >
                <div className="flex flex-1 flex-col gap-2">
                    <label
                        htmlFor="passkey-name"
                        className="text-sm font-semibold"
                    >
                        Passkey name
                    </label>
                    <input
                        id="passkey-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Work MacBook"
                        required
                        maxLength={255}
                        disabled={isLoading}
                    />
                </div>
                <Button
                    variant="outline"
                    disabled={isLoading || !isSupported || !name.trim()}
                >
                    {isLoading ? 'Waiting for your device…' : 'Add passkey'}
                </Button>
            </form>
            {!isSupported && (
                <p className="text-sm text-muted-foreground">
                    Passkeys aren’t supported in this browser. You can still
                    sign in with your password.
                </p>
            )}
            <PasskeyFeedback error={error} />
            {passkeys.length === 0 ? (
                <p className="border-t py-6 text-center text-muted-foreground">
                    You haven’t added any passkeys yet.
                </p>
            ) : (
                <ul className="divide-y divide-slate-100 border-t border-slate-100">
                    {passkeys.map((passkey) => (
                        <li
                            key={passkey.id}
                            className="flex items-center gap-4 py-5"
                        >
                            <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent text-primary">
                                <HugeiconsIcon
                                    icon={Key01Icon}
                                    size={20}
                                    aria-hidden="true"
                                />
                            </span>
                            <div className="flex min-w-0 flex-1 flex-col">
                                <strong className="wrap-anywhere">
                                    {passkey.name}
                                </strong>
                                <p className="text-sm text-muted-foreground">
                                    Added {date(passkey.created_at)} ·{' '}
                                    {passkey.last_used_at
                                        ? `Last used ${date(passkey.last_used_at)}`
                                        : 'Not used yet'}
                                </p>
                            </div>
                            <Form
                                action={destroy(passkey.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setRegistered(false)}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing || isLoading}
                                        aria-label={`Remove ${passkey.name}`}
                                    >
                                        {processing ? 'Removing…' : 'Remove'}
                                    </Button>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
