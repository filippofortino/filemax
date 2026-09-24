import { Form, Head, Link, usePage } from '@inertiajs/react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { passwordHint } from '@/lib/format';
import type { SharedProps } from '@/lib/types';
import { login } from '@/routes';
import { store } from '@/routes/register';

export default function Register() {
    const { passwordRequirements } = usePage<SharedProps>().props;

    return (
        <AuthLayout
            title="Create your account"
            description="Filemax is for the Mediamax team. Use your company email to get started."
            footer={
                <>
                    Already have an account? <Link href={login()}>Sign in</Link>
                </>
            }
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
                                Company email
                            </label>
                            <input
                                id="email"
                                name="email"
                                type="email"
                                autoComplete="email"
                                placeholder="you@mediamaxcommunication.it"
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
