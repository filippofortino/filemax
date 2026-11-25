/** A chosen file and the folder it sat in, relative to what was dropped ("Shoot/Photos"). */
export type PickedFile = { file: File; folder?: string };

export type Picked = {
    files: PickedFile[];
    /** System files and empty folders, which are never sent. */
    skipped: number;
};

const SYSTEM_FILES = new Set(['.DS_Store', 'Thumbs.db', 'desktop.ini']);

function isSystemFile(name: string) {
    return SYSTEM_FILES.has(name) || name.startsWith('._');
}

function dirname(path: string) {
    const slash = path.lastIndexOf('/');
    return slash > 0 ? path.slice(0, slash) : undefined;
}

/** Files from the picker, with their folder when it was a `webkitdirectory` picker. */
export function pickedFiles(files: FileList | File[]): Picked {
    const all = Array.from(files);
    const kept = all.filter((file) => !isSystemFile(file.name));
    return {
        files: kept.map((file) => ({
            file,
            folder: dirname(file.webkitRelativePath),
        })),
        skipped: all.length - kept.length,
    };
}

/**
 * Files from a drop, walking into dropped folders. The entries must be taken
 * synchronously in the drop event: the browser empties the DataTransfer after it.
 */
export function droppedFiles(data: DataTransfer): Promise<Picked> {
    const entries = Array.from(data.items)
        .map((item) => item.webkitGetAsEntry?.())
        .filter((entry): entry is FileSystemEntry => Boolean(entry));
    const files = Array.from(data.files);
    if (!entries.some((entry) => entry.isDirectory))
        return Promise.resolve(pickedFiles(files));
    return collect(entries);
}

async function collect(entries: FileSystemEntry[]): Promise<Picked> {
    const picked: Picked = { files: [], skipped: 0 };
    async function walk(entry: FileSystemEntry, folder?: string) {
        if (entry.isFile) {
            const file = await new Promise<File>((resolve, reject) =>
                (entry as FileSystemFileEntry).file(resolve, reject),
            );
            if (isSystemFile(file.name)) picked.skipped++;
            else picked.files.push({ file, folder });
            return;
        }
        const path = folder ? `${folder}/${entry.name}` : entry.name;
        const reader = (entry as FileSystemDirectoryEntry).createReader();
        const children: FileSystemEntry[] = [];
        // readEntries hands out at most 100 entries a call, until an empty batch.
        for (;;) {
            const batch = await new Promise<FileSystemEntry[]>(
                (resolve, reject) => reader.readEntries(resolve, reject),
            );
            if (!batch.length) break;
            children.push(...batch);
        }
        if (!children.length) picked.skipped++;
        children.sort((a, b) =>
            a.name.localeCompare(b.name, undefined, { numeric: true }),
        );
        for (const child of children) await walk(child, path);
    }
    for (const entry of entries) await walk(entry);
    return picked;
}

export type FolderNode<T> = {
    name: string;
    /** "Shoot/Photos"; "" for the top. */
    path: string;
    folders: FolderNode<T>[];
    files: T[];
    count: number;
    size: number;
};

/** Nests items by their folder path, keeping the order they came in. */
export function folderTree<T>(
    items: T[],
    folderOf: (item: T) => string | null | undefined,
    sizeOf: (item: T) => number,
): FolderNode<T> {
    const root: FolderNode<T> = {
        name: '',
        path: '',
        folders: [],
        files: [],
        count: 0,
        size: 0,
    };
    for (const item of items) {
        let node = root;
        const trail = [root];
        for (const name of (folderOf(item) ?? '').split('/').filter(Boolean)) {
            const path = node.path ? `${node.path}/${name}` : name;
            let child = node.folders.find((folder) => folder.name === name);
            if (!child) {
                child = {
                    name,
                    path,
                    folders: [],
                    files: [],
                    count: 0,
                    size: 0,
                };
                node.folders.push(child);
            }
            node = child;
            trail.push(child);
        }
        node.files.push(item);
        for (const folder of trail) {
            folder.count++;
            folder.size += sizeOf(item);
        }
    }
    return root;
}

/** The folder at a path, or the top when the path is gone. */
export function folderAt<T>(root: FolderNode<T>, path: string): FolderNode<T> {
    let node = root;
    for (const name of path.split('/').filter(Boolean)) {
        const child = node.folders.find((folder) => folder.name === name);
        if (!child) return root;
        node = child;
    }
    return node;
}

export function plural(count: number, noun = 'file') {
    return `${count} ${count === 1 ? noun : `${noun}s`}`;
}
