import { Form, Head, Link } from '@inertiajs/react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { PasskeyButton } from '@/components/passkey-button';
import { Button } from '@/components/ui/button';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

export default function Login() {
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
                                    id="email"
                                    name="email"
                                    type="email"
                                    autoComplete="email"
                                    placeholder="you@mediamaxcommunication.it"
                                    required
                                    aria-invalid={!!errors.email}
                                />
                                <ErrorMessage>{errors.email}</ErrorMessage>
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
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    placeholder="••••••••"
                                    required
                                    aria-invalid={!!errors.password}
                                />
                                <ErrorMessage>{errors.password}</ErrorMessage>
                            </div>
                        </div>
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
