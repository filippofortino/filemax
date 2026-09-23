import { useState } from 'react';
import { initials } from '@/lib/format';
import { cn } from '@/lib/utils';

export function Avatar({
    name,
    url,
    className,
}: {
    name: string;
    url: string | null;
    className?: string;
}) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);

    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-full border border-blue-200 bg-blue-50 text-xs font-bold text-primary',
                className,
            )}
        >
            {url && failedUrl !== url ? (
                <img
                    src={url}
                    alt=""
                    className="size-full object-cover"
                    onError={() => setFailedUrl(url)}
                />
            ) : (
                initials(name)
            )}
        </span>
    );
}
