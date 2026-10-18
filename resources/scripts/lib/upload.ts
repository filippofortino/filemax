import { request, uploadPart } from '@/lib/http';

const UPLOAD_CONCURRENCY = 4;

export type UploadEntry = {
    key: string;
    file: File;
    remote?: { id: string; part_size: number };
    status: 'waiting' | 'uploading' | 'done' | 'failed';
    loaded: number;
    error?: string;
};

/**
 * Uploads the entries' parts with a small worker pool, round-robin across files.
 * Parts the server already has are skipped, so calling it again resumes a failed upload.
 */
export async function uploadEntries(
    entries: UploadEntry[],
    urls: {
        sign: (fileId: string, part: number) => string;
        complete: (fileId: string) => string;
    },
    signal: AbortSignal,
    patch: (
        key: string,
        values: Partial<Pick<UploadEntry, 'status' | 'loaded' | 'error'>>,
    ) => void,
    onTransferred: (bytes: number) => void,
): Promise<void> {
    const files = entries.map((entry) => ({
        entry,
        count: Math.max(
            1,
            Math.ceil(entry.file.size / entry.remote!.part_size),
        ),
        nextPart: 1,
        completed: 0,
        failed: false,
        loaded: new Map<number, number>(),
    }));
    let nextFile = 0;
    function claimPart() {
        // ponytail: scan files per claim; use a ready-file queue if huge batches make this costly.
        for (let checked = 0; checked < files.length; checked++) {
            const file = files[nextFile];
            nextFile = (nextFile + 1) % files.length;
            if (!file.failed && file.nextPart <= file.count) {
                return { file, part: file.nextPart++ };
            }
        }
    }
    async function worker() {
        while (!signal.aborted) {
            const job = claimPart();
            if (!job) return;
            const { file, part } = job;
            const { entry } = file;
            const remote = entry.remote!;
            if (part === 1) {
                patch(entry.key, { status: 'uploading' });
            }
            try {
                const signed = await request<{
                    url: string;
                    headers: Record<string, string>;
                    completed: boolean;
                }>(urls.sign(remote.id, part), 'POST', undefined, signal);
                if (signal.aborted) return;
                if (file.failed) continue;
                const offset = (part - 1) * remote.part_size;
                const blob = entry.file.slice(
                    offset,
                    Math.min(offset + remote.part_size, entry.file.size),
                );
                function progress(loaded: number) {
                    if (signal.aborted) return;
                    const previous = file.loaded.get(part) ?? 0;
                    const current = Math.max(
                        previous,
                        Math.min(blob.size, loaded),
                    );
                    file.loaded.set(part, current);
                    patch(entry.key, {
                        loaded: [...file.loaded.values()].reduce(
                            (sum, bytes) => sum + bytes,
                            0,
                        ),
                    });
                    onTransferred(signed.completed ? 0 : current - previous);
                }
                if (!signed.completed) {
                    await uploadPart(
                        signed.url,
                        signed.headers,
                        blob,
                        signal,
                        progress,
                    );
                }
                if (signal.aborted) return;
                progress(blob.size);
                file.completed++;
                if (!file.failed && file.completed === file.count) {
                    await request(
                        urls.complete(remote.id),
                        'POST',
                        undefined,
                        signal,
                    );
                    if (signal.aborted) return;
                    patch(entry.key, {
                        status: 'done',
                        loaded: entry.file.size,
                    });
                }
            } catch (cause) {
                if (signal.aborted) return;
                file.failed = true;
                patch(entry.key, {
                    status: 'failed',
                    error: (cause as Error).message,
                });
            }
        }
    }
    await Promise.all(Array.from({ length: UPLOAD_CONCURRENCY }, worker));
}
