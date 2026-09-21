import { Download01Icon, Loading03Icon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { request } from '@/lib/http';
import type { SharedProps } from '@/lib/types';
import { download as downloadFile } from '@/routes/shared/files';

export function FileDownload({
    token,
    fileId,
    name,
    onDownload,
}: {
    token: string;
    fileId: string;
    name: string;
    onDownload?: () => void;
}) {
    const { csrf_token } = usePage<SharedProps>().props;
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const controller = useRef<AbortController | null>(null);
    useEffect(() => () => controller.current?.abort(), []);

    async function start() {
        if (controller.current) return;
        const active = new AbortController();
        controller.current = active;
        setBusy(true);
        setError('');
        try {
            const result = await request<{ url: string }>(
                downloadFile.url({ token, file: fileId }),
                csrf_token,
                'POST',
                undefined,
                active.signal,
            );
            onDownload?.();
            window.location.assign(result.url);
        } catch (caught) {
            if (!active.signal.aborted)
                setError(
                    caught instanceof Error
                        ? caught.message
                        : 'The download could not be started.',
                );
        } finally {
            controller.current = null;
            if (!active.signal.aborted) setBusy(false);
        }
    }

    return (
        <span className="flex max-w-40 shrink-0 flex-col items-end gap-1">
            <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={start}
                disabled={busy}
                aria-label={`Download ${name}`}
                aria-busy={busy}
            >
                <HugeiconsIcon
                    icon={busy ? Loading03Icon : Download01Icon}
                    size={16}
                    strokeWidth={2}
                    aria-hidden="true"
                />
            </Button>
            <ErrorMessage>{error}</ErrorMessage>
        </span>
    );
}
