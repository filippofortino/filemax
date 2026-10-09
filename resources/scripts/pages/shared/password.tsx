import { LockKeyIcon, ViewIcon, ViewOffIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorMessage, Shell } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { unlock } from '@/routes/shared';

export default function Password({
    token,
    sender,
}: {
    token: string;
    sender: { name: string; email: string } | null;
}) {
    const [passwordVisible, setPasswordVisible] = useState(false);

    return (
        <Shell recipient>
            <Head title="Password required">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="flex flex-1 justify-center px-5 py-6 md:items-center md:px-10 md:pb-2">
                <section className="flex w-full max-w-md min-w-0 flex-col gap-7 md:rounded-xl md:border md:bg-background md:p-10">
                    <div className="flex flex-col items-center gap-4 text-center">
                        <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <HugeiconsIcon
                                icon={LockKeyIcon}
                                size={28}
                                strokeWidth={2}
                                aria-hidden="true"
                            />
                        </span>
                        <h1>This transfer needs a password</h1>
                        <p className="text-base text-muted-foreground">
                            {sender
                                ? `${sender.name} protected these files.`
                                : 'These files are password protected.'}{' '}
                            If you don’t have the password, ask{' '}
                            {sender ? 'them' : 'the sender'} for it.
                        </p>
                    </div>
                    <Form
                        action={unlock(token)}
                        className="flex flex-col gap-4"
                        resetOnSuccess={['password']}
                    >
                        {({ errors, processing, clearErrors }) => (
                            <>
                                <div className="flex flex-col gap-2">
                                    <label
                                        className="text-sm font-semibold"
                                        htmlFor="unlock-password"
                                    >
                                        Password
                                    </label>
                                    <div className="relative">
                                        <input
                                            id="unlock-password"
                                            name="password"
                                            type={
                                                passwordVisible
                                                    ? 'text'
                                                    : 'password'
                                            }
                                            autoComplete="off"
                                            spellCheck={false}
                                            required
                                            maxLength={72}
                                            disabled={processing}
                                            aria-invalid={!!errors.password}
                                            aria-describedby={
                                                errors.password
                                                    ? 'unlock-error'
                                                    : undefined
                                            }
                                            className="pr-12"
                                            onChange={() =>
                                                clearErrors('password')
                                            }
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="absolute top-0 right-0 text-muted-foreground"
                                            aria-label={
                                                passwordVisible
                                                    ? 'Hide password'
                                                    : 'Show password'
                                            }
                                            aria-pressed={passwordVisible}
                                            disabled={processing}
                                            onClick={() =>
                                                setPasswordVisible(
                                                    !passwordVisible,
                                                )
                                            }
                                        >
                                            <HugeiconsIcon
                                                icon={
                                                    passwordVisible
                                                        ? ViewOffIcon
                                                        : ViewIcon
                                                }
                                                size={20}
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </div>
                                    <ErrorMessage id="unlock-error">
                                        {errors.password}
                                    </ErrorMessage>
                                </div>
                                <Button
                                    type="submit"
                                    size="lg"
                                    disabled={processing}
                                    aria-busy={processing}
                                >
                                    {processing ? 'Unlocking…' : 'Unlock files'}
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </main>
        </Shell>
    );
}
