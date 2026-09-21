import { Key01Icon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, router, usePage } from '@inertiajs/react';
import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useState } from 'react';
import { Shell } from '@/components/filemax';
import { PasskeyFeedback } from '@/components/passkey-button';
import { Button } from '@/components/ui/button';
import { date } from '@/lib/format';
import type { SharedProps } from '@/lib/types';
import { passkeys as accountPasskeys } from '@/routes/account';
import { destroy, registrationOptions, store } from '@/routes/passkey';

type Passkey = {
    id: number;
    name: string;
    created_at: string;
    last_used_at: string | null;
};

export default function Passkeys({ passkeys }: { passkeys: Passkey[] }) {
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
                router.visit(accountPasskeys());
            }
        },
    });

    return (
        <Shell active="account">
            <Head title="Passkeys" />
            <main className="mx-auto w-full max-w-3xl px-5 py-12 sm:px-8">
                <div className="mb-8 flex flex-col gap-2">
                    <h1>Passkeys</h1>
                    <p className="text-muted-foreground">
                        Sign in with your fingerprint, face, or device lock.
                        Your password remains available.
                    </p>
                </div>
                {(status === 'passkey-deleted' || registered) && (
                    <p
                        className="mb-6 rounded-lg border border-blue-200 bg-accent px-4 py-3 text-slate-700"
                        role="status"
                    >
                        {status === 'passkey-deleted'
                            ? 'Passkey removed.'
                            : 'Passkey added. You can now use it to sign in.'}
                    </p>
                )}
                <section
                    className="mb-6 rounded-xl border bg-white p-6"
                    aria-labelledby="add-passkey-title"
                >
                    <h2 id="add-passkey-title" className="mb-2 text-xl">
                        Add a passkey
                    </h2>
                    <p className="mb-5 text-sm text-muted-foreground">
                        Give it a name you’ll recognize, such as “Work MacBook”.
                    </p>
                    <form
                        className="flex flex-col gap-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setRegistered(false);
                            void register(name.trim());
                        }}
                    >
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
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
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                    placeholder="Work MacBook"
                                    required
                                    maxLength={255}
                                    disabled={isLoading}
                                />
                            </div>
                            <Button
                                disabled={
                                    isLoading || !isSupported || !name.trim()
                                }
                            >
                                {isLoading
                                    ? 'Waiting for your device…'
                                    : 'Add passkey'}
                            </Button>
                        </div>
                        {!isSupported && (
                            <p className="text-sm text-muted-foreground">
                                Passkeys aren’t supported in this browser. You
                                can still sign in with your password.
                            </p>
                        )}
                        <PasskeyFeedback error={error} />
                    </form>
                </section>
                <section
                    className="rounded-xl border bg-white p-6"
                    aria-labelledby="saved-passkeys-title"
                >
                    <h2 id="saved-passkeys-title" className="mb-2 text-xl">
                        Your passkeys
                    </h2>
                    <p className="mb-5 text-sm text-muted-foreground">
                        Remove keys for devices you no longer use. Resetting
                        your password keeps your passkeys.
                    </p>
                    {passkeys.length === 0 ? (
                        <p className="py-6 text-center text-muted-foreground">
                            You haven’t added any passkeys yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {passkeys.map((passkey) => (
                                <li
                                    key={passkey.id}
                                    className="flex flex-wrap items-center gap-4 py-5"
                                >
                                    <HugeiconsIcon
                                        icon={Key01Icon}
                                        size={24}
                                        aria-hidden="true"
                                    />
                                    <div className="min-w-0 flex-1 basis-40">
                                        <strong className="break-words">
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
                                                disabled={
                                                    processing || isLoading
                                                }
                                                aria-label={`Remove ${passkey.name}`}
                                            >
                                                {processing
                                                    ? 'Removing…'
                                                    : 'Remove'}
                                            </Button>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </main>
        </Shell>
    );
}
