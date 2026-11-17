import { LockKeyIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AuthLayout, Brand, ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { passwordHint } from '@/lib/format';
import type { SharedProps } from '@/lib/types';
import { login } from '@/routes';
import { check, store } from '@/routes/register';

const signIn = (
    <>
        Already have an account? <Link href={login()}>Sign in</Link>
    </>
);

export default function Register({ signupOpen }: { signupOpen: boolean }) {
    const { passwordRequirements } = usePage<SharedProps>().props;
    // While sign-up is closed, a work email on an organization's allowed domain unlocks the form.
    const [workEmail, setWorkEmail] = useState<string | null>(null);

    if (!signupOpen && workEmail === null)
        return (
            <main className="flex min-h-svh flex-col items-center justify-center gap-8 bg-muted px-5 py-8">
                <Head title="Sign-up is by invitation" />
                <Brand large />
                <section className="flex w-full max-w-sm flex-col gap-6 rounded-xl border bg-background p-7 md:p-9">
                    <div className="flex flex-col gap-4">
                        <span className="inline-flex size-14 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <HugeiconsIcon
                                icon={LockKeyIcon}
                                size={24}
                                aria-hidden="true"
                            />
                        </span>
                        <div className="flex flex-col gap-1.5">
                            <h1 className="text-2xl">
                                Sign-up is by invitation for now
                            </h1>
                            <p className="text-muted-foreground">
                                Invited? Open the link in your invite email. If
                                your organization already uses Filemax, enter
                                your work email to join it.
                            </p>
                        </div>
                    </div>
                    <Form
                        action={check()}
                        className="flex flex-col gap-5"
                        onSuccess={() =>
                            setWorkEmail(
                                (
                                    document.getElementById(
                                        'work-email',
                                    ) as HTMLInputElement
                                ).value,
                            )
                        }
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="flex flex-col gap-2">
                                    <label
                                        className="text-sm font-semibold"
                                        htmlFor="work-email"
                                    >
                                        Work email
                                    </label>
                                    <input
                                        id="work-email"
                                        name="email"
                                        type="email"
                                        autoComplete="email"
                                        required
                                        maxLength={255}
                                        aria-invalid={!!errors.email}
                                        aria-describedby="work-email-error"
                                    />
                                    <ErrorMessage id="work-email-error">
                                        {errors.email}
                                    </ErrorMessage>
                                </div>
                                <Button
                                    type="submit"
                                    className="w-full"
                                    size="lg"
                                    disabled={processing}
                                >
                                    Continue
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
                <p className="max-w-sm text-center text-sm text-muted-foreground">
                    {signIn}
                </p>
            </main>
        );

    return (
        <AuthLayout
            title="Create your account"
            description={
                signupOpen
                    ? 'Use your email to get started with Filemax.'
                    : 'Your email belongs to an organization on Filemax. Create your account to join it.'
            }
            footer={signIn}
        >
            <Head title="Create account" />
            <Form
                action={store()}
                className="flex flex-col gap-5"
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ errors, processing }) => (
                    <>
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
                            <ErrorMessage>{errors.name}</ErrorMessage>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                className="text-sm font-semibold"
                                htmlFor="email"
                            >
                                {signupOpen ? 'Email' : 'Work email'}
                            </label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                defaultValue={workEmail ?? undefined}
                                placeholder="you@example.com"
                                required
                                maxLength={255}
                                aria-invalid={!!errors.email}
                            />
                            <ErrorMessage>{errors.email}</ErrorMessage>
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
                            <ErrorMessage>{errors.password}</ErrorMessage>
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
                        <Button
                            type="submit"
                            className="w-full"
                            size="lg"
                            disabled={processing}
                        >
                            {processing
                                ? 'Creating account…'
                                : 'Create account'}
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
