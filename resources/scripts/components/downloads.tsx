import {
    Download01Icon,
    FolderDownloadIcon,
    LoaderCircleIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
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

/** Asks for the zip of the transfer, or of one folder, and waits until it is ready. Resolves to its download URL. */
async function prepareArchive(
    token: string,
    folder: string | undefined,
    signal: AbortSignal,
    onProgress: (progress: number) => void,
    onRequested?: () => void,
) {
    const query = folder ? { query: { folder } } : undefined;
    let result = await request<Archive>(
        download.url(token, query),
        'POST',
        undefined,
        signal,
    );
    onRequested?.();
    while (result.status === 'pending' || result.status === 'processing') {
        onProgress(result.progress);
        await new Promise((resolve) => window.setTimeout(resolve, 1500));
        signal.throwIfAborted();
        result = await request<Archive>(
            archive.url(token, query),
            'GET',
            undefined,
            signal,
        );
    }
    if (result.status !== 'ready' || !result.url) {
        throw new Error(
            folder
                ? 'The folder’s zip could not be prepared. Try again, or download its files individually.'
                : 'The archive could not be prepared. Try Download all again, or download the files individually.',
        );
    }
    return result.url;
}

export function DownloadAll({
    token,
    totalSize,
    singleFileId,
    folder,
    disabled = false,
    onDownload,
    variant = 'default',
    size = 'lg',
}: {
    token: string;
    totalSize: number;
    singleFileId?: string;
    /** Zips only this folder: "Download folder". */
    folder?: string;
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
            if (singleFileId) {
                const result = await request<{ url: string }>(
                    downloadFile.url({ transfer: token, file: singleFileId }),
                    'POST',
                    undefined,
                    active.signal,
                );
                onDownload?.();
                window.location.assign(result.url);
                return;
            }

            window.location.assign(
                await prepareArchive(
                    token,
                    folder,
                    active.signal,
                    setProgress,
                    onDownload,
                ),
            );
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
                    icon={
                        busy
                            ? LoaderCircleIcon
                            : folder
                              ? FolderDownloadIcon
                              : Download01Icon
                    }
                    className={busy ? 'animate-spin' : undefined}
                    size={20}
                    strokeWidth={2}
                    aria-hidden="true"
                />
                {busy
                    ? singleFileId
                        ? 'Starting download…'
                        : `Preparing files… ${progress}%`
                    : `${singleFileId ? 'Download' : folder ? 'Download folder' : 'Download all'} · ${bytes(totalSize)}`}
            </Button>
            {busy && !singleFileId && (
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

/** A folder's zip as an icon button, for folder rows and tiles. */
export function FolderDownload({
    token,
    folder,
    name,
    onDownload,
    variant = 'default',
}: {
    token: string;
    folder: string;
    name: string;
    onDownload?: () => void;
    variant?: 'default' | 'owner';
}) {
    const [progress, setProgress] = useState<number | null>(null);
    const [error, setError] = useState('');
    // Said once when the zip starts and once when it's ready; the percentages stay in the button's name.
    const [announcement, setAnnouncement] = useState('');
    const controller = useRef<AbortController | null>(null);
    useEffect(() => () => controller.current?.abort(), []);

    async function start() {
        if (controller.current) return;
        const active = new AbortController();
        controller.current = active;
        setProgress(0);
        setError('');
        setAnnouncement(`Preparing ${name} as a zip.`);
        try {
            const url = await prepareArchive(
                token,
                folder,
                active.signal,
                setProgress,
                onDownload,
            );
            setAnnouncement(`${name} zip is ready. Downloading.`);
            window.location.assign(url);
        } catch (caught) {
            setAnnouncement('');
            if (!active.signal.aborted)
                setError(
                    caught instanceof Error
                        ? caught.message
                        : 'The download could not be started.',
                );
        } finally {
            controller.current = null;
            if (!active.signal.aborted) setProgress(null);
        }
    }

    const busy = progress !== null;
    return (
        <span className="flex max-w-40 shrink-0 flex-col items-end gap-1">
            <Button
                type="button"
                variant={variant === 'owner' ? 'ghost' : 'outline'}
                size={variant === 'owner' ? 'icon-sm' : 'icon'}
                // Stays enabled while preparing so the ring keeps its colour and focus stays put; start() ignores repeat presses.
                onClick={start}
                aria-label={
                    busy
                        ? `Preparing ${name} as a zip, ${progress}%`
                        : `Download ${name} as a zip`
                }
                aria-busy={busy}
            >
                {busy ? (
                    <ProgressRing
                        value={progress || null}
                        size={variant === 'owner' ? 22 : 32}
                        strokeWidth={variant === 'owner' ? 2.5 : 3}
                    >
                        {variant !== 'owner' && progress ? progress : null}
                    </ProgressRing>
                ) : (
                    <HugeiconsIcon
                        icon={FolderDownloadIcon}
                        size={variant === 'owner' ? 17 : 18}
                        aria-hidden="true"
                    />
                )}
            </Button>
            <span role="status" className="sr-only">
                {announcement}
            </span>
            <ErrorMessage>{error}</ErrorMessage>
        </span>
    );
}

/** A ring that fills as the zip prepares, for a button too small for a bar. A null value spins as an arc while the progress is unknown. */
function ProgressRing({
    value,
    size,
    strokeWidth,
    children,
}: {
    value: number | null;
    size: number;
    strokeWidth: number;
    /** Shown in the middle: the percentage. */
    children?: ReactNode;
}) {
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const filled = Math.min(100, Math.max(0, value ?? 25)) / 100;
    return (
        <span
            aria-hidden="true"
            className="relative inline-flex shrink-0 items-center justify-center text-primary"
            style={{ width: size, height: size }}
        >
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                className={
                    value === null
                        ? '-rotate-90 animate-spin motion-reduce:animate-none'
                        : '-rotate-90'
                }
            >
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    className="opacity-15"
                />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    strokeLinecap="round"
                    strokeDasharray={circumference}
                    strokeDashoffset={circumference * (1 - filled)}
                    className="transition-[stroke-dashoffset] duration-500 ease-out motion-reduce:transition-none"
                />
            </svg>
            {children !== undefined && children !== null && (
                <span className="absolute text-[10px] leading-none font-semibold text-foreground tabular-nums">
                    {children}
                </span>
            )}
        </span>
    );
}
