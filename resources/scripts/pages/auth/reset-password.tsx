import { Form, Head, Link } from '@inertiajs/react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import { update } from '@/routes/password';

export default function ResetPassword({
    token,
    email,
}: {
    token: string;
    email: string;
}) {
    return (
        <AuthLayout
            title="Choose a new password"
            description="Use at least 8 characters to keep your account secure."
            footer={<Link href={login()}>Back to sign in</Link>}
        >
            <Head title="Choose password" />
            <Form
                action={update()}
                className="form-stack"
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ errors, processing }) => (
                    <>
                        <input type="hidden" name="token" value={token} />
                        <div className="flex flex-col gap-2">
                            <label className="field-label" htmlFor="email">
                                Company email
                            </label>
                            <input
                                className="field"
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
                            <label className="field-label" htmlFor="password">
                                New password
                            </label>
                            <input
                                className="field"
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                required
                                minLength={8}
                                aria-invalid={!!errors.password}
                            />
                            <ErrorMessage>{errors.password}</ErrorMessage>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                className="field-label"
                                htmlFor="password_confirmation"
                            >
                                Confirm new password
                            </label>
                            <input
                                className="field"
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required
                                minLength={8}
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
