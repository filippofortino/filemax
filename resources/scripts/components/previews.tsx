import {
    ArrowLeft01Icon,
    ArrowRight01Icon,
    Cancel01Icon,
    File01Icon,
    MusicNote01Icon,
    PlayIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import {
    useEffect,
    useRef,
    useState,
    type KeyboardEvent,
    type ReactNode,
} from 'react';
import { DownloadAll, FileDownload } from '@/components/downloads';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
} from '@/components/ui/empty';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { bytes } from '@/lib/format';
import { request } from '@/lib/http';
import { cn } from '@/lib/utils';
import { preview as previewRoute } from '@/routes/shared/files';

export type FilePreview = {
    kind: 'image' | 'pdf' | 'video' | 'audio' | null;
    pending: boolean;
    page_count: number | null;
    /** Image and PDF: the small copy. Video and audio: the original, read for a frame and the duration. */
    thumbnail_url: string | null;
};

export type PreviewFile = {
    id: string;
    name: string;
    size: number;
    preview: FilePreview;
};

/** Signed URLs change on every reload; keep the first one per file so polling doesn't reload every tile. */
export function useStableThumbnails() {
    const urls = useRef(new Map<string, string>());
    return (file: PreviewFile) => {
        if (file.preview.thumbnail_url && !urls.current.has(file.id)) {
            urls.current.set(file.id, file.preview.thumbnail_url);
        }
        return urls.current.get(file.id) ?? null;
    };
}

function extension(name: string) {
    const ext = name.includes('.') ? name.split('.').pop() : '';
    return (ext ?? '').toUpperCase();
}

function duration(seconds: number) {
    if (!Number.isFinite(seconds)) return null;
    const total = Math.round(seconds);
    const hours = Math.floor(total / 3600);
    const rest = `${Math.floor((total % 3600) / 60)}:${String(total % 60).padStart(2, '0')}`;
    return hours ? `${hours}:${rest.padStart(5, '0')}` : rest;
}

function Pill({ children }: { children: string }) {
    return (
        <span className="absolute bottom-2 left-2 rounded-full bg-slate-900/80 px-2 text-xs leading-5 font-semibold text-white">
            {children}
        </span>
    );
}

/** What fills a tile: the copy, a frame the browser grabs from the video, or an icon. */
function ThumbnailContent({
    file,
    url,
    small = false,
}: {
    file: PreviewFile;
    url: string | null;
    small?: boolean;
}) {
    const [length, setLength] = useState<string | null>(null);
    const [broken, setBroken] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const { kind, pending, page_count } = file.preview;
    // A cached image can finish before hydration attaches onLoad, so check on mount too.
    const markLoadedIfReady = (element: HTMLImageElement | null) => {
        if (element?.complete && element.naturalWidth > 0) setLoaded(true);
    };
    const skeleton = !loaded && (
        <Skeleton className="absolute inset-0 rounded-none bg-slate-200" />
    );

    if (pending) {
        return (
            <span className="flex size-full flex-col items-center justify-center gap-2 text-muted-foreground">
                <Spinner className={small ? 'size-3.5' : 'size-5.5'} />
                {!small && <span className="text-xs">Preparing preview</span>}
            </span>
        );
    }

    if ((kind === 'image' || kind === 'pdf') && url && !broken) {
        return (
            <>
                {skeleton}
                <img
                    ref={markLoadedIfReady}
                    src={url}
                    alt=""
                    loading="lazy"
                    onLoad={() => setLoaded(true)}
                    onError={() => setBroken(true)}
                    className={cn(
                        'size-full',
                        kind === 'pdf'
                            ? 'bg-slate-200 object-contain object-bottom pt-[8%]'
                            : 'object-cover',
                    )}
                />
                {!small && kind === 'pdf' && page_count && (
                    <Pill>{`PDF · ${page_count} ${page_count === 1 ? 'page' : 'pages'}`}</Pill>
                )}
            </>
        );
    }

    if (kind === 'video' && url && !broken) {
        return (
            <>
                {skeleton}
                <video
                    src={`${url}#t=0.5`}
                    preload="metadata"
                    muted
                    playsInline
                    aria-hidden="true"
                    tabIndex={-1}
                    onLoadedMetadata={(event) => {
                        setLength(duration(event.currentTarget.duration));
                        // Safari stops at metadata with preload="metadata", so loadeddata may never come.
                        setLoaded(true);
                    }}
                    onError={() => setBroken(true)}
                    className="pointer-events-none size-full bg-slate-800 object-cover"
                />
                <span className="absolute inset-0 flex items-center justify-center">
                    <span
                        className={cn(
                            'inline-flex items-center justify-center rounded-full bg-white/90 text-slate-900',
                            small ? 'size-5' : 'size-11',
                        )}
                    >
                        <HugeiconsIcon
                            icon={PlayIcon}
                            size={small ? 10 : 18}
                            aria-hidden="true"
                        />
                    </span>
                </span>
                {!small && length && <Pill>{length}</Pill>}
            </>
        );
    }

    if (kind === 'audio') {
        return (
            <span className="flex size-full items-center justify-center bg-blue-50 text-primary">
                <HugeiconsIcon
                    icon={MusicNote01Icon}
                    size={small ? 16 : 36}
                    aria-hidden="true"
                />
                {!small && url && (
                    <audio
                        src={url}
                        preload="metadata"
                        aria-hidden="true"
                        onLoadedMetadata={(event) =>
                            setLength(duration(event.currentTarget.duration))
                        }
                    />
                )}
                {!small && length && <Pill>{length}</Pill>}
            </span>
        );
    }

    return (
        <span className="flex size-full flex-col items-center justify-center gap-1.5 text-muted-foreground">
            <HugeiconsIcon
                icon={File01Icon}
                size={small ? 16 : 32}
                aria-hidden="true"
            />
            {!small && extension(file.name) && (
                <span className="text-xs font-semibold">
                    {extension(file.name)}
                </span>
            )}
        </span>
    );
}

/** A 48 × 36 thumbnail for list rows; a button when it can open the viewer. */
export function FileThumbnail({
    file,
    url,
    onOpen,
}: {
    file: PreviewFile;
    url: string | null;
    onOpen?: () => void;
}) {
    const className =
        'relative h-9 w-12 shrink-0 overflow-hidden rounded-md bg-muted';
    const content = <ThumbnailContent file={file} url={url} small />;
    return onOpen ? (
        <button
            type="button"
            className={cn(className, 'cursor-zoom-in')}
            onClick={onOpen}
            aria-label={`Preview ${file.name}`}
        >
            {content}
        </button>
    ) : (
        <span className={className} aria-hidden="true">
            {content}
        </span>
    );
}

export function FileTile({
    token,
    file,
    url,
    onOpen,
}: {
    token: string;
    file: PreviewFile;
    url: string | null;
    onOpen: () => void;
}) {
    return (
        <li className="group relative flex min-w-0 flex-col gap-1.5">
            <button
                type="button"
                className="relative aspect-4/3 w-full cursor-zoom-in overflow-hidden rounded-lg bg-muted"
                onClick={onOpen}
                aria-label={`Preview ${file.name}`}
            >
                <ThumbnailContent file={file} url={url} />
            </button>
            <span className="flex min-w-0 items-baseline justify-between gap-2">
                <span
                    className="truncate text-sm font-medium"
                    title={file.name}
                >
                    {file.name}
                </span>
                <small className="shrink-0 text-xs text-muted-foreground tabular-nums">
                    {bytes(file.size)}
                </small>
            </span>
            {/* Always shown on touch screens; on hover or keyboard focus elsewhere. */}
            <span className="absolute top-1 right-1 transition-opacity duration-150 ease-out pointer-fine:opacity-0 pointer-fine:group-focus-within:opacity-100 pointer-fine:group-hover:opacity-100 [&_button]:bg-background/95 [&_button]:shadow-sm">
                <FileDownload token={token} fileId={file.id} name={file.name} />
            </span>
        </li>
    );
}

function ViewerMessage({
    title,
    children,
    media = <HugeiconsIcon icon={File01Icon} aria-hidden="true" />,
}: {
    title: string;
    children: string;
    media?: ReactNode;
}) {
    return (
        <Empty>
            <EmptyHeader>
                <EmptyMedia
                    variant="icon"
                    className="size-12 rounded-xl bg-white/10 text-slate-50 [&_svg:not([class*='size-'])]:size-6"
                >
                    {media}
                </EmptyMedia>
                <EmptyTitle className="text-lg font-semibold text-slate-50">
                    {title}
                </EmptyTitle>
                <EmptyDescription className="text-slate-400">
                    {children}
                </EmptyDescription>
            </EmptyHeader>
        </Empty>
    );
}

function ViewerMedia({ token, file }: { token: string; file: PreviewFile }) {
    const [url, setUrl] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [unplayable, setUnplayable] = useState(false);
    const { kind, pending } = file.preview;

    useEffect(() => {
        if (!kind || pending) return;
        const controller = new AbortController();
        request<{ url: string }>(
            previewRoute.url({ transfer: token, file: file.id }),
            'POST',
            undefined,
            controller.signal,
        )
            .then((result) => setUrl(result.url))
            .catch((caught: unknown) => {
                if (!controller.signal.aborted)
                    setError(
                        caught instanceof Error
                            ? caught.message
                            : 'The preview could not be opened.',
                    );
            });
        return () => controller.abort();
    }, [token, file.id, kind, pending]);

    if (pending) {
        return (
            <ViewerMessage title="Preparing the preview" media={<Spinner />}>
                This file arrived a moment ago. You can already download it.
            </ViewerMessage>
        );
    }
    if (!kind) {
        return (
            <ViewerMessage
                title={`No preview for ${extension(file.name) || 'this'} files`}
            >
                Download it to open it on your device.
            </ViewerMessage>
        );
    }
    if (unplayable) {
        return (
            <ViewerMessage title="Your browser can’t play this file">
                Download it to open it on your device.
            </ViewerMessage>
        );
    }
    if (error) {
        return (
            <ViewerMessage title="Preview unavailable">{error}</ViewerMessage>
        );
    }
    if (!url) {
        return <Spinner className="size-7.5 text-slate-400" />;
    }
    if (kind === 'image') {
        return (
            <img
                src={url}
                alt={file.name}
                className="max-h-full max-w-full object-contain"
            />
        );
    }
    if (kind === 'pdf') {
        return (
            <iframe
                src={url}
                title={file.name}
                className="size-full max-w-4xl rounded-md bg-white"
            />
        );
    }
    if (kind === 'video') {
        return (
            <video
                src={url}
                controls
                autoPlay
                playsInline
                onError={() => setUnplayable(true)}
                className="max-h-full max-w-full rounded-md bg-black"
            />
        );
    }
    return (
        <div className="flex w-full max-w-md flex-col items-center gap-5 rounded-xl bg-white/5 px-6 py-8">
            <span className="inline-flex size-20 items-center justify-center rounded-full bg-primary/30 text-blue-200">
                <HugeiconsIcon
                    icon={MusicNote01Icon}
                    size={36}
                    aria-hidden="true"
                />
            </span>
            <audio
                src={url}
                controls
                autoPlay
                onError={() => setUnplayable(true)}
                className="w-full"
            />
        </div>
    );
}

/** Full-screen viewer: one file at a time, with arrows and the file's own download. */
export function PreviewViewer({
    token,
    files,
    index,
    onIndexChange,
}: {
    token: string;
    files: PreviewFile[];
    index: number | null;
    onIndexChange: (index: number | null) => void;
}) {
    const file = index === null ? null : files[index];
    const move = (step: number) =>
        index !== null &&
        onIndexChange((index + step + files.length) % files.length);

    function onKeyDown(event: KeyboardEvent) {
        if (event.target instanceof HTMLMediaElement) return;
        if (event.key === 'ArrowLeft') move(-1);
        if (event.key === 'ArrowRight') move(1);
    }

    const navButton =
        'rounded-full bg-white/10 text-slate-50 hover:bg-white/20 hover:text-slate-50 focus-visible:outline-white';
    const kbd = 'bg-white/10 text-slate-200';
    const navigate = (step: number, label: string, className?: string) =>
        files.length > 1 && (
            <Button
                variant="ghost"
                size="icon"
                className={cn(navButton, className)}
                onClick={() => move(step)}
                aria-label={label}
            >
                <HugeiconsIcon
                    icon={step < 0 ? ArrowLeft01Icon : ArrowRight01Icon}
                    size={22}
                    aria-hidden="true"
                />
            </Button>
        );

    return (
        <Dialog
            open={file !== null}
            onOpenChange={(open) => !open && onIndexChange(null)}
        >
            <DialogContent
                showCloseButton={false}
                onKeyDown={onKeyDown}
                className="top-0 left-0 flex h-dvh w-full max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none bg-slate-950 p-0 text-slate-50 ring-0 motion-safe:data-ending-style:scale-100 motion-safe:data-starting-style:scale-100"
            >
                {file && index !== null && (
                    <>
                        <div className="flex min-h-16 shrink-0 items-center gap-3 py-2 pr-2 pl-4 md:min-h-18 md:pr-4 md:pl-6">
                            <div className="flex min-w-0 flex-1 flex-col">
                                <DialogTitle className="truncate font-sans text-sm font-semibold tracking-normal text-slate-50 md:text-base">
                                    {file.name}
                                </DialogTitle>
                                <p className="text-xs text-slate-400 md:text-sm">
                                    {index + 1} of {files.length} ·{' '}
                                    {bytes(file.size)}
                                </p>
                            </div>
                            <div className="hidden w-56 md:block">
                                <DownloadAll
                                    key={file.id}
                                    token={token}
                                    totalSize={file.size}
                                    singleFileId={file.id}
                                    size="default"
                                />
                            </div>
                            <DialogClose
                                render={
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className={cn(
                                            navButton,
                                            'bg-transparent',
                                        )}
                                    />
                                }
                                aria-label="Close preview"
                            >
                                <HugeiconsIcon
                                    icon={Cancel01Icon}
                                    size={22}
                                    aria-hidden="true"
                                />
                            </DialogClose>
                        </div>
                        <div className="relative flex min-h-0 flex-1 items-center justify-center md:px-24 md:pb-4">
                            {navigate(
                                -1,
                                'Previous file',
                                'absolute top-1/2 left-7 hidden -translate-y-1/2 md:inline-flex',
                            )}
                            <ViewerMedia
                                key={file.id}
                                token={token}
                                file={file}
                            />
                            {navigate(
                                1,
                                'Next file',
                                'absolute top-1/2 right-7 hidden -translate-y-1/2 md:inline-flex',
                            )}
                        </div>
                        <p className="hidden shrink-0 pb-4 text-center text-xs text-slate-400 md:block">
                            Use{' '}
                            <KbdGroup>
                                <Kbd className={kbd}>←</Kbd>
                                <Kbd className={kbd}>→</Kbd>
                            </KbdGroup>{' '}
                            to move between files.{' '}
                            <Kbd className={kbd}>Esc</Kbd> closes.
                        </p>
                        <div className="flex shrink-0 items-center gap-2 px-4 pt-3 pb-6 md:hidden">
                            {navigate(-1, 'Previous file')}
                            <div className="min-w-0 flex-1">
                                <DownloadAll
                                    key={file.id}
                                    token={token}
                                    totalSize={file.size}
                                    singleFileId={file.id}
                                />
                            </div>
                            {navigate(1, 'Next file')}
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
