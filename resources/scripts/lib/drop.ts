import { bytes } from '@/lib/format';
import type { DropFileType, DropRules } from '@/lib/types';

/** Filemax's own per-file limit; a Drop can lower it, never raise it. */
export const MAX_FILE_SIZE = 100e9;

/** How each preset reads on screen. Matching uses the server's extension lists. */
export const FILE_TYPE_LABELS: Record<DropFileType, string[]> = {
    documents: [
        'PDF',
        'DOC',
        'DOCX',
        'XLS',
        'XLSX',
        'PPT',
        'PPTX',
        'TXT',
        'CSV',
    ],
    images: ['JPG', 'PNG', 'HEIC', 'GIF', 'WebP', 'SVG', 'PSD', 'AI'],
    videos: ['MP4', 'MOV', 'AVI', 'WebM', 'MKV'],
};
const FILE_TYPE_ORDER: DropFileType[] = ['documents', 'images', 'videos'];
const FILE_TYPE_NOUNS: Record<DropFileType, string> = {
    documents: 'document',
    images: 'image',
    videos: 'video',
};

function join(items: string[], word: 'and' | 'or'): string {
    return items.length < 2
        ? items.join('')
        : `${items.slice(0, -1).join(', ')} ${word} ${items[items.length - 1]}`;
}

function pickedTypes(rules: DropRules): DropFileType[] {
    return FILE_TYPE_ORDER.filter((type) => rules.file_types.includes(type));
}

/** "Images and videos", "Documents and .indd", or null when any type is accepted. */
export function typesLabel(rules: DropRules): string | null {
    const words = [
        ...pickedTypes(rules),
        ...rules.extensions.map((extension) => `.${extension}`),
    ];
    if (!words.length) return null;
    const label = join(words, 'and');
    return label.charAt(0).toUpperCase() + label.slice(1);
}

/** The accepted extensions as the design lists them: "JPG, PNG, …, .indd". */
export function acceptedList(rules: DropRules): string[] {
    return [
        ...pickedTypes(rules).flatMap((type) => FILE_TYPE_LABELS[type]),
        ...rules.extensions.map((extension) => `.${extension}`),
    ];
}

export function fileSizeLimit(rules: DropRules): number {
    return rules.max_file_size ?? MAX_FILE_SIZE;
}

/** "Images and videos · up to 2 GB per file", the one-line summary of a Drop. */
export function rulesSummary(rules: DropRules, withTotal = false): string {
    return [
        typesLabel(rules) ?? 'Any file type',
        `up to ${bytes(fileSizeLimit(rules))} per file`,
        ...(withTotal && rules.max_submission_size
            ? [`${bytes(rules.max_submission_size)} per submission`]
            : []),
    ].join(' · ');
}

function extensionOf(name: string): string {
    const dot = name.lastIndexOf('.');
    return dot > 0 ? name.slice(dot + 1).toLowerCase() : '';
}

/**
 * Why a picked file won't be sent, or '' when it fits. Checks run in order: type, size, then the running
 * total of the files accepted before it, so a later small file can still fit after a big one is rejected.
 */
export function rejectReason(
    file: { name: string; size: number },
    rules: DropRules & { accepted_extensions: string[] },
    acceptedSoFar: number,
): string {
    if (
        rules.accepted_extensions.length &&
        !rules.accepted_extensions.includes(extensionOf(file.name))
    ) {
        const types = pickedTypes(rules);
        if (rules.extensions.length || !types.length)
            return 'Not a file type this Drop accepts';
        const nouns = types.map((type) => FILE_TYPE_NOUNS[type]);
        return `Not ${/^[aeiou]/.test(nouns[0]) ? 'an' : 'a'} ${join(nouns, 'or')}`;
    }
    if (file.size > fileSizeLimit(rules))
        return `Over ${bytes(fileSizeLimit(rules))}`;
    if (
        rules.max_submission_size !== null &&
        acceptedSoFar + file.size > rules.max_submission_size
    )
        return `Over the ${bytes(rules.max_submission_size)} total`;
    return '';
}
