import { FingerprintPatternIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { usePage } from '@inertiajs/react';
import { usePasskeyVerify } from '@laravel/passkeys/react';
import { ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { passkeys } from '@/routes/account';
import { confirm, confirmOptions, login, loginOptions } from '@/routes/passkey';

export function PasskeyFeedback({ error }: { error: string | null }) {
    const { url } = usePage();
    const expired =
        error === 'CSRF token mismatch.' ||
        error === 'Unauthenticated.' ||
        error?.includes('status 419');
    return (
        <ErrorMessage>
            {expired ? (
                <>
                    Your session expired.{' '}
                    <a className="underline" href={url}>
                        Reload this page
                    </a>{' '}
                    and try again.
                </>
            ) : (
                error
            )}
        </ErrorMessage>
    );
}

export function PasskeyButton({
    confirmation = false,
}: {
    confirmation?: boolean;
}) {
    const { verify, isLoading, isSupported, error } = usePasskeyVerify({
        routes: {
            options: confirmation ? confirmOptions.url() : loginOptions.url(),
            submit: confirmation ? confirm.url() : login.url(),
        },
        onSuccess: ({ redirect }) =>
            window.location.assign(
                redirect ?? (confirmation ? passkeys.url() : home.url()),
            ),
    });

    return (
        <div className="flex flex-col gap-2">
            <Button
                type="button"
                variant="outline"
                size="lg"
                className="w-full"
                disabled={isLoading || !isSupported}
                onClick={() => void verify()}
            >
                <HugeiconsIcon
                    icon={FingerprintPatternIcon}
                    size={20}
                    aria-hidden="true"
                />
                {isLoading
                    ? 'Waiting for your passkey…'
                    : confirmation
                      ? 'Confirm with passkey'
                      : 'Sign in with passkey'}
            </Button>
            {!isSupported && (
                <p className="text-sm text-muted-foreground">
                    Passkeys aren’t supported in this browser. You can use your
                    password.
                </p>
            )}
            <PasskeyFeedback error={error} />
        </div>
    );
}
