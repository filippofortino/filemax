import { Form, Head, Link } from '@inertiajs/react';
import { AuthLayout, ErrorMessage } from '@/components/filemax';
import { PasskeyButton } from '@/components/passkey-button';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword({
    hasPasskeys,
}: {
    hasPasskeys: boolean;
}) {
    return (
        <AuthLayout
            title="Confirm it’s you"
            description="Confirm your identity to manage passkeys. Then retry the action you were taking."
            footer={<Link href={home()}>Back to Filemax</Link>}
        >
            <Head title="Confirm your identity" />
            <Form
                action={store()}
                className="flex flex-col gap-5"
                resetOnSuccess={['password']}
            >
                {({ errors, processing }) => (
                    <>
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
                                autoComplete="current-password"
                                required
                                autoFocus
                                aria-invalid={!!errors.password}
                            />
                            <ErrorMessage>{errors.password}</ErrorMessage>
                        </div>
                        <Button size="lg" disabled={processing}>
                            {processing ? 'Confirming…' : 'Confirm password'}
                        </Button>
                    </>
                )}
            </Form>
            {hasPasskeys && <PasskeyButton confirmation />}
        </AuthLayout>
    );
}
