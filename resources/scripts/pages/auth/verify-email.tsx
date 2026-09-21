import { Form, Head, Link, usePage } from '@inertiajs/react';
import { AuthLayout } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/lib/types';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail() {
    const user = usePage<SharedProps>().props.auth.user;
    return (
        <AuthLayout
            title="Check your email"
            description="One last step: verify your email to send files and open transfers shared with your teams."
            footer={
                <Link href={logout()} method="post" as="button">
                    Sign out
                </Link>
            }
        >
            <Head title="Verify email" />
            <p className="muted">
                We sent a verification link to{' '}
                <strong className="break-all">{user?.email}</strong>. Open the
                link in your email to continue.
            </p>
            <Form action={send()}>
                {({ processing }) => (
                    <Button className="w-full" size="lg" disabled={processing}>
                        {processing ? 'Sending…' : 'Resend verification email'}
                    </Button>
                )}
            </Form>
        </AuthLayout>
    );
}
