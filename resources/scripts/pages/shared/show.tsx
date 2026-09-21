import { Head } from '@inertiajs/react';
import { DownloadAll, FileDownload } from '@/components/downloads';
import { FileRow, Shell } from '@/components/filemax';
import { date, initials } from '@/lib/format';

type SharedTransfer = {
    token: string;
    title: string;
    message: string | null;
    sender: { name: string; email: string };
    files: { id: string; name: string; size: number; mime_type: string }[];
    total_size: number;
    expires_at: string;
};

export default function SharedTransferPage({
    transfer,
}: {
    transfer: SharedTransfer;
}) {
    return (
        <Shell recipient>
            <Head title={transfer.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <main>
                <section className="recipient-card">
                    <div className="flex flex-col gap-3.5 max-[720px]:gap-3">
                        <div className="sender-identity leading-[1.3]">
                            <span className="avatar min-[721px]:size-9">
                                {initials(transfer.sender.name)}
                            </span>
                            <span>
                                <strong>{transfer.sender.name}</strong>
                                <span className="sender-separator"> · </span>
                                <span className="sender-organization">
                                    Mediamax Communication
                                </span>
                            </span>
                        </div>
                        <h1 className="text-pretty wrap-anywhere">
                            {transfer.title}
                        </h1>
                        {transfer.message && (
                            <p className="text-[15px] leading-[1.55] wrap-anywhere whitespace-pre-wrap text-[#3B4552]">
                                {transfer.message}
                            </p>
                        )}
                    </div>
                    <DownloadAll
                        token={transfer.token}
                        totalSize={transfer.total_size}
                    />
                    <ul className="border-y border-border">
                        {transfer.files.map((file) => (
                            <li key={file.id}>
                                <FileRow name={file.name} size={file.size}>
                                    <FileDownload
                                        token={transfer.token}
                                        fileId={file.id}
                                        name={file.name}
                                    />
                                </FileRow>
                            </li>
                        ))}
                    </ul>
                    <p className="muted text-center text-[13px]">
                        {transfer.files.length}{' '}
                        {transfer.files.length === 1 ? 'file' : 'files'} ·
                        available until {date(transfer.expires_at)}
                    </p>
                </section>
            </main>
        </Shell>
    );
}
