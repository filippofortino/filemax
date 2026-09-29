import { Form, Head, Link } from '@inertiajs/react';
import { useRef } from 'react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { PasskeyButton } from '@/components/passkey-button';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

export default function Login() {
    const emailInput = useRef<HTMLInputElement>(null);
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <AuthLayout
            title="Sign in"
            description="To send files, and to open transfers shared with your teams."
            footer={
                <>
                    New to Filemax?{' '}
                    <Link href={register()}>Create an account</Link>
                </>
            }
        >
            <Head title="Sign in" />
            <Form
                action={store()}
                className="flex flex-col gap-5"
                resetOnSuccess={['password']}
                onError={(errors) => {
                    requestAnimationFrame(() => {
                        if (errors.email) {
                            emailInput.current?.focus();
                        } else if (errors.password) {
                            passwordInput.current?.focus();
                        }
                    });
                }}
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-4">
                            <div className="flex flex-col gap-2">
                                <label
                                    className="text-sm font-semibold"
                                    htmlFor="email"
                                >
                                    Email
                                </label>
                                <input
                                    ref={emailInput}
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    placeholder="you@mediamaxcommunication.it"
                                    required
                                    aria-invalid={!!errors.email}
                                    aria-describedby={
                                        errors.email
                                            ? 'login-email-error'
                                            : undefined
                                    }
                                />
                                <ErrorMessage id="login-email-error">
                                    {errors.email}
                                </ErrorMessage>
                            </div>
                            <div className="flex flex-col gap-2">
                                <div className="flex items-baseline justify-between">
                                    <label
                                        className="text-sm font-semibold"
                                        htmlFor="password"
                                    >
                                        Password
                                    </label>
                                    <Link
                                        href={request()}
                                        className="text-sm font-medium"
                                    >
                                        Forgot password?
                                    </Link>
                                </div>
                                <input
                                    ref={passwordInput}
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    placeholder="••••••••"
                                    required
                                    aria-invalid={!!errors.password}
                                    aria-describedby={
                                        errors.password
                                            ? 'login-password-error'
                                            : undefined
                                    }
                                />
                                <ErrorMessage id="login-password-error">
                                    {errors.password}
                                </ErrorMessage>
                            </div>
                        </div>
                        <label className="-my-3 flex min-h-11 cursor-pointer items-center gap-2.5 self-start">
                            <input type="checkbox" name="remember" value="1" />
                            Keep me signed in
                        </label>
                        <Button
                            type="submit"
                            className="w-full"
                            size="lg"
                            disabled={processing}
                        >
                            {processing ? 'Signing in…' : 'Sign in'}
                        </Button>
                    </>
                )}
            </Form>
            <PasskeyButton />
        </AuthLayout>
    );
}
