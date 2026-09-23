import { Form, Head, Link, usePage } from '@inertiajs/react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { passwordHint } from '@/lib/format';
import type { SharedProps } from '@/lib/types';
import { login } from '@/routes';
import { update } from '@/routes/password';

export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    const { passwordRequirements } = usePage<SharedProps>().props;

    return (
        <AuthLayout
            title="Choose a new password"
            description={passwordHint(passwordRequirements)}
            footer={<Link href={login()}>Back to sign in</Link>}
        >
            <Head title="Choose password" />
            <Form
                action={update()}
                className="flex flex-col gap-5"
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="token" value={token} />
                        <div className="flex flex-col gap-2">
                            <label
                                className="text-sm font-semibold"
                                htmlFor="email"
                            >
                                Company email
                            </label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                defaultValue={email}
                                autoComplete="email"
                                required
                                aria-invalid={!!errors.email}
                            />
                            <ErrorMessage>{errors.email}</ErrorMessage>
                            <ErrorMessage>{errors.token}</ErrorMessage>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                className="text-sm font-semibold"
                                htmlFor="password"
                            >
                                New password
                            </label>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                required
                                minLength={passwordRequirements.min}
                                aria-invalid={!!errors.password}
                            />
                            <ErrorMessage>{errors.password}</ErrorMessage>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                className="text-sm font-semibold"
                                htmlFor="password_confirmation"
                            >
                                Confirm new password
                            </label>
                            <input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required
                                minLength={passwordRequirements.min}
                            />
                        </div>
                        <Button
                            className="w-full"
                            size="lg"
                            disabled={processing}
                        >
                            {processing ? 'Saving…' : 'Reset password'}
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
