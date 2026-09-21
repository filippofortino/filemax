import { Form, Head, Link } from '@inertiajs/react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword() {
    return (
        <AuthLayout
            title="Forgot your password?"
            description="Enter your company email and we’ll send you a link to reset it."
            footer={<Link href={login()}>Back to sign in</Link>}
        >
            <Head title="Reset password" />
            <Form action={email()} className="flex flex-col gap-5">
                {({ errors, processing }) => (
                    <>
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
                                autoComplete="email"
                                placeholder="you@mediamaxcommunication.it"
                                required
                                aria-invalid={!!errors.email}
                            />
                            <ErrorMessage>{errors.email}</ErrorMessage>
                        </div>
                        <Button
                            className="w-full"
                            size="lg"
                            disabled={processing}
                        >
                            {processing ? 'Sending…' : 'Send reset link'}
                        </Button>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
