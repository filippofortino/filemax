import { Form, Head, Link } from '@inertiajs/react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import { store } from '@/routes/register';

export default function Register() {
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
                className="form-stack"
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ errors, processing }) => (
                    <>
                        <div className="flex flex-col gap-2">
                            <label className="field-label" htmlFor="name">
                                Full name
                            </label>
                            <input
                                className="field"
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
                            <label className="field-label" htmlFor="email">
                                Company email
                            </label>
                            <input
                                className="field"
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
                            <label className="field-label" htmlFor="password">
                                Password
                            </label>
                            <input
                                className="field"
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                placeholder="At least 8 characters"
                                minLength={8}
                                required
                                aria-invalid={!!errors.password}
                            />
                            <ErrorMessage>{errors.password}</ErrorMessage>
                        </div>
                        <div className="flex flex-col gap-2">
                            <label
                                className="field-label"
                                htmlFor="password_confirmation"
                            >
                                Confirm password
                            </label>
                            <input
                                className="field"
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                minLength={8}
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
