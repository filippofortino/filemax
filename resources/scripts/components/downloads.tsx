import { Download01Icon, LoaderCircleIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { useEffect, useRef, useState } from 'react';
import { ErrorMessage } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { bytes } from '@/lib/format';
import { request } from '@/lib/http';
import { archive, download } from '@/routes/shared';
import { download as downloadFile } from '@/routes/shared/files';

type Archive = {
    status: 'pending' | 'processing' | 'ready' | 'failed' | null;
    progress: number;
    url: string | null;
};

export function DownloadAll({
    token,
    totalSize,
    disabled = false,
    onDownload,
    variant = 'default',
    size = 'lg',
}: {
    token: string;
    totalSize: number;
    disabled?: boolean;
    onDownload?: () => void;
    variant?: 'default' | 'outline';
    size?: 'default' | 'lg';
}) {
    const [busy, setBusy] = useState(false);
    const [progress, setProgress] = useState(0);
    const [error, setError] = useState('');
    const controller = useRef<AbortController | null>(null);

    useEffect(() => () => controller.current?.abort(), []);

    async function start() {
        if (controller.current) return;
        const active = new AbortController();
        controller.current = active;
        setBusy(true);
        setProgress(0);
        setError('');
        try {
            let result = await request<Archive>(
                download.url(token),
                'POST',
                undefined,
                active.signal,
            );
            onDownload?.();
            while (
                result.status === 'pending' ||
                result.status === 'processing'
            ) {
                setProgress(result.progress);
                await new Promise((resolve) =>
                    window.setTimeout(resolve, 1500),
                );
                active.signal.throwIfAborted();
                result = await request<Archive>(
                    archive.url(token),
                    'GET',
                    undefined,
                    active.signal,
                );
            }
            if (result.status !== 'ready' || !result.url) {
                throw new Error(
                    'The archive could not be prepared. Try Download all again, or download the files individually.',
                );
            }
            window.location.assign(result.url);
        } catch (caught) {
            if (!active.signal.aborted)
                setError(
                    caught instanceof Error
                        ? caught.message
                        : 'The download could not be started. Please try again.',
                );
        } finally {
            controller.current = null;
            if (!active.signal.aborted) setBusy(false);
        }
    }

    return (
        <div className="flex w-full flex-col gap-2">
            <Button
                type="button"
                size={size}
                variant={variant}
                className="w-full"
                onClick={start}
                disabled={disabled || busy}
                aria-busy={busy}
            >
                <HugeiconsIcon
                    icon={busy ? LoaderCircleIcon : Download01Icon}
                    className={busy ? 'animate-spin' : undefined}
                    size={20}
                    strokeWidth={2}
                    aria-hidden="true"
                />
                {busy
                    ? `Preparing files… ${progress}%`
                    : `Download all · ${bytes(totalSize)}`}
            </Button>
            {busy && (
                <div className="flex flex-col gap-2">
                    <progress
                        value={progress}
                        max={100}
                        aria-label="Preparing download archive"
                    />
                    <p
                        role="status"
                        className="text-center text-xs text-muted-foreground"
                    >
                        Preparing your ZIP. Large transfers can take a few
                        minutes.
                    </p>
                </div>
            )}
            <ErrorMessage>{error}</ErrorMessage>
        </div>
    );
}

export function FileDownload({
    token,
    fileId,
    name,
    onDownload,
    variant = 'default',
}: {
    token: string;
    fileId: string;
    name: string;
    onDownload?: () => void;
    variant?: 'default' | 'owner';
}) {
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
                downloadFile.url({ transfer: token, file: fileId }),
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
                variant={variant === 'owner' ? 'ghost' : 'outline'}
                size={variant === 'owner' ? 'icon-sm' : 'icon'}
                onClick={start}
                disabled={busy}
                aria-label={`Download ${name}`}
                aria-busy={busy}
            >
                <HugeiconsIcon
                    icon={busy ? LoaderCircleIcon : Download01Icon}
                    className={busy ? 'animate-spin' : undefined}
                    size={16}
                    strokeWidth={2}
                    aria-hidden="true"
                />
            </Button>
            <ErrorMessage>{error}</ErrorMessage>
        </span>
    );
}
