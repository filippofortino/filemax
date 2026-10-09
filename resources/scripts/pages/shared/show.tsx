import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar } from '@/components/avatar';
import { DownloadAll, FileDownload } from '@/components/downloads';
import { FileRow, PasswordBadge, Shell } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { dateTime } from '@/lib/format';

type SharedTransfer = {
    token: string;
    title: string;
    message: string | null;
    password_protected: boolean;
    sender: { name: string; email: string; avatar_url: string | null } | null;
    files: { id: string; name: string; size: number; mime_type: string }[];
    total_size: number;
    expires_at: string;
};

export default function SharedTransferPage({
    transfer,
}: {
    transfer: SharedTransfer;
}) {
    const [allFiles, setAllFiles] = useState(false);
    return (
        <Shell recipient>
            <Head title={transfer.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main className="flex flex-1 justify-center px-5 py-6 md:items-center md:px-10 md:pb-2">
                <section className="flex w-full max-w-xl min-w-0 flex-col gap-6 md:gap-7 md:rounded-xl md:border md:bg-background md:p-10">
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
                                    <span className="hidden md:inline">
                                        {' '}
                                        ·{' '}
                                    </span>
                                    <span className="block md:inline">
                                        Mediamax Communication
                                    </span>
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
                    <DownloadAll
                        token={transfer.token}
                        totalSize={transfer.total_size}
                    />
                    <div>
                        <ul className="border-y border-border">
                            {transfer.files
                                .slice(0, allFiles ? undefined : 4)
                                .map((file) => (
                                    <li key={file.id}>
                                        <FileRow
                                            name={file.name}
                                            size={file.size}
                                            variant="recipient"
                                        >
                                            <FileDownload
                                                token={transfer.token}
                                                fileId={file.id}
                                                name={file.name}
                                            />
                                        </FileRow>
                                    </li>
                                ))}
                        </ul>
                        {transfer.files.length > 4 && (
                            <Button
                                className="mt-3 h-auto min-h-0 p-0 text-sm"
                                variant="link"
                                onClick={() => setAllFiles(!allFiles)}
                            >
                                {allFiles
                                    ? 'Show fewer files'
                                    : `Show all ${transfer.files.length} files`}
                            </Button>
                        )}
                    </div>
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
