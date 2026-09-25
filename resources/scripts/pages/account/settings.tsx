import { Key01Icon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, router, useForm, usePage } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { ErrorMessage, Shell } from '@/components/filemax';
import { sessionExpired } from '@/components/passkey-button';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
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

function failed(
    title: string,
    retry: () => void,
    description = 'Check your connection and try again.',
    action = 'Try again',
) {
    toast.add({
        id: title,
        type: 'error',
        title,
        description,
        timeout: 0,
        priority: 'high',
        actionProps: {
            children: action,
            onClick: () => {
                toast.close(title);
                retry();
            },
        },
    });
    return false;
}

export default function Settings({ passkeys }: { passkeys: Passkey[] }) {
    const { auth, passwordRequirements } = usePage<SharedProps>().props;
    const passwordForm = useRef<HTMLFormElement>(null);
    const password = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

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
                        <form
                            ref={passwordForm}
                            id="password-form"
                            className="flex flex-col gap-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                password.put(updatePassword.url(), {
                                    errorBag: 'updatePassword',
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        password.reset();
                                        toast.add({
                                            title: 'Password updated',
                                            description:
                                                'You’re signed out everywhere else.',
                                        });
                                    },
                                    onNetworkError: () =>
                                        failed('Password not updated', () =>
                                            passwordForm.current?.requestSubmit(),
                                        ),
                                });
                            }}
                        >
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
                                        value={password.data.current_password}
                                        onChange={(event) =>
                                            password.setData(
                                                'current_password',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            !!password.errors.current_password
                                        }
                                    />
                                    <ErrorMessage>
                                        {password.errors.current_password}
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
                                        minLength={passwordRequirements.min}
                                        required
                                        value={password.data.password}
                                        onChange={(event) =>
                                            password.setData(
                                                'password',
                                                event.target.value,
                                            )
                                        }
                                        aria-describedby="password-requirements"
                                        aria-invalid={
                                            !!password.errors.password
                                        }
                                    />
                                    <ErrorMessage>
                                        {password.errors.password}
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
                                        minLength={passwordRequirements.min}
                                        required
                                        value={
                                            password.data.password_confirmation
                                        }
                                        onChange={(event) =>
                                            password.setData(
                                                'password_confirmation',
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            !!password.errors
                                                .password_confirmation
                                        }
                                    />
                                    <ErrorMessage>
                                        {password.errors.password_confirmation}
                                    </ErrorMessage>
                                </div>
                            </div>
                            <p
                                id="password-requirements"
                                className="text-sm text-muted-foreground"
                            >
                                {passwordHint(passwordRequirements)} Changing it
                                keeps your passkeys and signs you out everywhere
                                else.
                            </p>
                            <Button
                                type="submit"
                                className="self-start"
                                disabled={password.processing}
                            >
                                {password.processing
                                    ? 'Updating…'
                                    : 'Update password'}
                            </Button>
                        </form>
                    </section>
                    <Passkeys passkeys={passkeys} />
                </div>
            </main>
        </Shell>
    );
}

function Profile({ user }: { user: User }) {
    const profileForm = useRef<HTMLFormElement>(null);
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
                ref={profileForm}
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
                            toast.add({ title: 'Profile saved' });
                        },
                        onNetworkError: () =>
                            failed('Profile not saved', () =>
                                profileForm.current?.requestSubmit(),
                            ),
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
                <Button
                    type="submit"
                    className="self-start"
                    disabled={profile.processing}
                >
                    {profile.processing ? 'Saving…' : 'Save profile'}
                </Button>
            </form>
        </section>
    );
}

function Passkeys({ passkeys }: { passkeys: Passkey[] }) {
    const passkeyForm = useRef<HTMLFormElement>(null);
    const [name, setName] = useState('');
    const { register, isLoading, isSupported } = usePasskeyRegister({
        routes: { options: registrationOptions.url(), submit: store.url() },
        onSuccess: () => {
            setName('');
            toast.add({
                title: 'Passkey added',
                description: 'You can now use it to sign in.',
            });
            router.reload();
        },
        onError: (error) => {
            if (error.message === 'Password confirmation required.') {
                router.visit(confirmPassword());
            } else if (sessionExpired(error.message)) {
                failed(
                    'Passkey not added',
                    () => window.location.reload(),
                    'Your session expired. Reload this page and try again.',
                    'Reload page',
                );
            } else {
                failed(
                    'Passkey not added',
                    () => passkeyForm.current?.requestSubmit(),
                    error.message,
                );
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
            <form
                ref={passkeyForm}
                id="passkey-form"
                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
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
                    type="submit"
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
                                onSuccess={() =>
                                    toast.add({
                                        title: 'Passkey removed',
                                        description: `“${passkey.name}” can no longer sign you in.`,
                                    })
                                }
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
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
