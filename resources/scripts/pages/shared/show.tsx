import {
    GridViewIcon,
    ListViewIcon,
    QrCodeIcon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, usePoll } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { Fragment, useEffect, useState } from 'react';
import { Avatar } from '@/components/avatar';
import {
    DownloadAll,
    FileDownload,
    FolderDownload,
} from '@/components/downloads';
import {
    FileRow,
    FolderTree,
    PasswordBadge,
    Shell,
} from '@/components/filemax';
import {
    FileThumbnail,
    FileTile,
    FolderTile,
    PreviewViewer,
    useStableThumbnails,
    type PreviewFile,
} from '@/components/previews';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { folderAt, folderTree, plural } from '@/lib/folders';
import { bytes, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type SharedTransfer = {
    token: string;
    url: string;
    title: string;
    message: string | null;
    password_protected: boolean;
    sender: { name: string; email: string; avatar_url: string | null } | null;
    organization: string | null;
    files: (PreviewFile & { mime_type: string; folder: string | null })[];
    total_size: number;
    expires_at: string;
};

export default function SharedTransferPage({
    transfer,
}: {
    transfer: SharedTransfer;
}) {
    const hasPreviews = transfer.files.some(
        (file) => file.preview.kind !== null || file.preview.pending,
    );
    const pending = transfer.files.some((file) => file.preview.pending);
    const [view, setView] = useState<'grid' | 'list'>(
        hasPreviews ? 'grid' : 'list',
    );
    const grid = hasPreviews && view === 'grid';
    const limit = grid ? 8 : 4;
    const [allFiles, setAllFiles] = useState(false);
    // Thumbnails open a folder; the list opens folders in place below the one the breadcrumb is on.
    const [folderPath, setFolderPath] = useState('');
    const tree = folderTree(
        transfer.files,
        (file) => file.folder,
        (file) => file.size,
    );
    const current = folderAt(tree, folderPath);
    const visibleFiles = current.files.slice(0, allFiles ? undefined : limit);
    function openFolder(path: string) {
        setFolderPath(path);
        setAllFiles(false);
    }
    const [viewing, setViewing] = useState<number | null>(null);
    const thumbnail = useStableThumbnails();
    const poll = usePoll(5000, { only: ['transfer'] }, { autoStart: false });
    useEffect(() => {
        if (pending) poll.start();
        else poll.stop();
    }, [pending, poll]);
    const displayUrl = transfer.url.replace(/^https?:\/\//, '');
    return (
        <Shell recipient organization={transfer.organization}>
            <Head title={transfer.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="flex flex-1 justify-center px-5 py-6 md:items-center md:px-10 md:pb-2">
                <section
                    className={cn(
                        'flex w-full max-w-xl min-w-0 flex-col gap-6 md:gap-7 md:rounded-xl md:border md:bg-background md:p-10',
                        grid && 'md:max-w-4xl',
                    )}
                >
                    <div className="flex flex-col gap-3 md:gap-3.5">
                        {transfer.sender && (
                            <div className="flex items-center gap-2.5 text-sm leading-snug text-muted-foreground">
                                <Avatar
                                    name={transfer.sender.name}
                                    url={transfer.sender.avatar_url}
                                    className="border-transparent bg-muted font-semibold text-foreground md:size-9 md:text-sm"
                                />
                                <span>
                                    <strong className="text-foreground">
                                        {transfer.sender.name}
                                    </strong>
                                    {transfer.organization && (
                                        <>
                                            <span className="hidden md:inline">
                                                {' '}
                                                ·{' '}
                                            </span>
                                            <span className="block md:inline">
                                                {transfer.organization}
                                            </span>
                                        </>
                                    )}
                                </span>
                            </div>
                        )}
                        <h1 className="text-3xl leading-tight text-pretty wrap-anywhere">
                            {transfer.title}
                        </h1>
                        {transfer.password_protected && <PasswordBadge />}
                        {transfer.message && (
                            <p className="text-base wrap-anywhere whitespace-pre-wrap text-muted-foreground">
                                {transfer.message}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-col gap-1">
                        <DownloadAll
                            token={transfer.token}
                            totalSize={transfer.total_size}
                            singleFileId={
                                transfer.files.length === 1
                                    ? transfer.files[0].id
                                    : undefined
                            }
                        />
                        <div className="flex items-center justify-center gap-1 text-sm text-muted-foreground">
                            <span className="md:hidden">
                                Need it on another phone?
                            </span>
                            <span className="hidden md:inline">
                                Need it on your phone?
                            </span>
                            <Dialog>
                                <DialogTrigger
                                    render={<Button variant="link" />}
                                    className="gap-1.5 px-1"
                                >
                                    <HugeiconsIcon
                                        icon={QrCodeIcon}
                                        size={16}
                                        strokeWidth={1.5}
                                        aria-hidden="true"
                                    />
                                    Show QR code
                                </DialogTrigger>
                                <DialogContent className="max-w-85 justify-items-center px-6 pt-12 pb-6 text-center">
                                    <DialogHeader className="gap-1.5">
                                        <DialogTitle className="text-xl leading-tight font-bold tracking-tight">
                                            Scan with a phone camera
                                        </DialogTitle>
                                        <DialogDescription>
                                            This page opens on the phone, ready
                                            to download.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <QRCodeSVG
                                        value={transfer.url}
                                        size={200}
                                        marginSize={4}
                                        fgColor="currentColor"
                                        role="img"
                                        aria-label={`QR code for ${displayUrl}`}
                                    />
                                    <p className="text-xs wrap-anywhere text-muted-foreground">
                                        {displayUrl}
                                    </p>
                                </DialogContent>
                            </Dialog>
                        </div>
                    </div>
                    <div className="flex flex-col gap-3">
                        {(hasPreviews || current.path) && (
                            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                {current.path ? (
                                    <FolderBreadcrumb
                                        path={current.path}
                                        onOpen={openFolder}
                                    />
                                ) : (
                                    <h2 className="font-sans text-sm font-semibold tracking-normal">
                                        {current.path && `${current.name} · `}
                                        {plural(current.count)} ·{' '}
                                        {bytes(current.size)}
                                    </h2>
                                )}
                                {hasPreviews && (
                                    <ToggleGroup
                                        aria-label="Show files as"
                                        value={[view]}
                                        variant="outline"
                                        spacing={0}
                                        onValueChange={(values) =>
                                            values[0] &&
                                            setView(
                                                values[0] as 'grid' | 'list',
                                            )
                                        }
                                    >
                                        <ToggleGroupItem
                                            value="grid"
                                            aria-label="Thumbnails"
                                        >
                                            <HugeiconsIcon
                                                icon={GridViewIcon}
                                                size={18}
                                                aria-hidden="true"
                                            />
                                        </ToggleGroupItem>
                                        <ToggleGroupItem
                                            value="list"
                                            aria-label="List"
                                        >
                                            <HugeiconsIcon
                                                icon={ListViewIcon}
                                                size={18}
                                                aria-hidden="true"
                                            />
                                        </ToggleGroupItem>
                                    </ToggleGroup>
                                )}
                            </div>
                        )}
                        {current.path && (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="font-sans text-sm font-semibold tracking-normal">
                                    {current.path && `${current.name} · `}
                                    {plural(current.count)} ·{' '}
                                    {bytes(current.size)}
                                </h2>
                                <div className="w-full sm:w-auto">
                                    <DownloadAll
                                        key={current.path}
                                        token={transfer.token}
                                        totalSize={current.size}
                                        folder={current.path}
                                        variant="outline"
                                        size="default"
                                    />
                                </div>
                            </div>
                        )}
                        {grid ? (
                            <ul className="grid grid-cols-2 gap-x-3 gap-y-4 md:grid-cols-4 md:gap-x-4 md:gap-y-5">
                                {current.folders.map((folder) => (
                                    <FolderTile
                                        key={folder.path}
                                        token={transfer.token}
                                        folder={folder}
                                        onOpen={() => openFolder(folder.path)}
                                    />
                                ))}
                                {visibleFiles.map((file) => (
                                    <FileTile
                                        key={file.id}
                                        token={transfer.token}
                                        file={file}
                                        url={thumbnail(file)}
                                        onOpen={() =>
                                            setViewing(
                                                transfer.files.indexOf(file),
                                            )
                                        }
                                    />
                                ))}
                            </ul>
                        ) : (
                            <ul className="border-y border-border">
                                <FolderTree
                                    node={{ ...current, files: visibleFiles }}
                                    fileKey={(file) => file.id}
                                    folderActions={(folder) => (
                                        <FolderDownload
                                            token={transfer.token}
                                            folder={folder.path}
                                            name={folder.name}
                                        />
                                    )}
                                    renderFile={(file) => (
                                        <li>
                                            <FileRow
                                                name={file.name}
                                                size={file.size}
                                                variant="recipient"
                                                thumbnail={
                                                    hasPreviews ? (
                                                        <FileThumbnail
                                                            file={file}
                                                            url={thumbnail(
                                                                file,
                                                            )}
                                                            onOpen={() =>
                                                                setViewing(
                                                                    transfer.files.indexOf(
                                                                        file,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                    ) : undefined
                                                }
                                            >
                                                <FileDownload
                                                    token={transfer.token}
                                                    fileId={file.id}
                                                    name={file.name}
                                                />
                                            </FileRow>
                                        </li>
                                    )}
                                />
                            </ul>
                        )}
                        {current.files.length > limit && (
                            <Button
                                className={cn(
                                    'h-auto min-h-0 p-0 text-sm',
                                    grid && 'self-center',
                                )}
                                variant="link"
                                onClick={() => setAllFiles(!allFiles)}
                            >
                                {allFiles
                                    ? 'Show fewer files'
                                    : `Show all ${current.files.length} files`}
                            </Button>
                        )}
                    </div>
                    <PreviewViewer
                        token={transfer.token}
                        files={transfer.files}
                        index={viewing}
                        onIndexChange={setViewing}
                    />
                    <p className="text-center text-sm text-muted-foreground">
                        {transfer.files.length}{' '}
                        {transfer.files.length === 1 ? 'file' : 'files'} ·
                        available until {dateTime(transfer.expires_at)}
                    </p>
                </section>
            </main>
        </Shell>
    );
}

const crumbClass =
    'inline-flex min-h-11 cursor-pointer items-center font-semibold text-primary hover:text-primary/90 hover:underline';

/** Where the thumbnails are: All files, then each folder down to the open one. */
function FolderBreadcrumb({
    path,
    onOpen,
}: {
    path: string;
    onOpen: (path: string) => void;
}) {
    const names = path.split('/');
    return (
        <Breadcrumb aria-label="Folder">
            <BreadcrumbList>
                <BreadcrumbItem>
                    <BreadcrumbLink
                        render={<button type="button" />}
                        className={crumbClass}
                        onClick={() => onOpen('')}
                    >
                        All files
                    </BreadcrumbLink>
                </BreadcrumbItem>
                {names.map((name, index) => {
                    const target = names.slice(0, index + 1).join('/');
                    return (
                        <Fragment key={target}>
                            <BreadcrumbSeparator />
                            <BreadcrumbItem className="min-w-0">
                                {index === names.length - 1 ? (
                                    <BreadcrumbPage className="truncate font-semibold">
                                        {name}
                                    </BreadcrumbPage>
                                ) : (
                                    <BreadcrumbLink
                                        render={<button type="button" />}
                                        className={crumbClass}
                                        onClick={() => onOpen(target)}
                                    >
                                        {name}
                                    </BreadcrumbLink>
                                )}
                            </BreadcrumbItem>
                        </Fragment>
                    );
                })}
            </BreadcrumbList>
        </Breadcrumb>
    );
}
