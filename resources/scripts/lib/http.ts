function xsrfHeaders(url: string): Record<string, string> {
    if (new URL(url, window.location.href).origin !== window.location.origin)
        return {};

    const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1];
    return token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {};
}

export async function request<T>(
    url: string,
    method = 'POST',
    data?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const response = await fetch(url, {
        method,
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...xsrfHeaders(url),
            'X-Requested-With': 'XMLHttpRequest',
        },
        ...(data === undefined ? {} : { body: JSON.stringify(data) }),
    }).catch((cause: unknown) => {
        if (
            signal?.aborted ||
            (cause instanceof Error && cause.name === 'AbortError')
        )
            throw cause;
        throw new Error(
            'Connection lost. Check your connection and try again.',
        );
    });
    const body: unknown = await response.json().catch((cause: unknown) => {
        if (
            signal?.aborted ||
            (cause instanceof Error && cause.name === 'AbortError')
        )
            throw cause;
        return {};
    });
    if (response.ok) return body as T;
    if (response.status === 401 || response.status === 419) {
        throw new Error(
            'Your session expired. Open Filemax in another tab, sign in if needed, then retry here. Keep this tab open.',
        );
    }
    if (response.status >= 500 && response.status < 600) {
        throw new Error(
            'Filemax is temporarily unavailable. Please try again in a moment.',
        );
    }
    const error =
        body && typeof body === 'object' && !Array.isArray(body)
            ? (body as Record<string, unknown>)
            : {};
    const validation =
        response.status === 422 &&
        error.errors &&
        typeof error.errors === 'object' &&
        !Array.isArray(error.errors)
            ? Object.values(error.errors)
                  .flat()
                  .filter(
                      (message) =>
                          typeof message === 'string' && message.trim(),
                  )
                  .join(' ')
            : '';
    const message =
        typeof error.message === 'string' && error.message.trim()
            ? error.message
            : '';
    throw new Error(
        validation ||
            message ||
            'This request could not be completed. Please try again.',
    );
}
export function uploadPart(
    url: string,
    headers: Record<string, string>,
    blob: Blob,
    signal: AbortSignal,
    progress: (loaded: number) => void,
): Promise<void> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const abort = () => xhr.abort();
        signal.addEventListener('abort', abort, { once: true });
        xhr.open('PUT', url);
        Object.entries({ ...headers, ...xsrfHeaders(url) }).forEach(
            ([name, value]) => xhr.setRequestHeader(name, value),
        );
        xhr.upload.onprogress = (event) => progress(event.loaded);
        xhr.onload = () =>
            xhr.status >= 200 && xhr.status < 300
                ? resolve()
                : reject(new Error('Upload interrupted.'));
        xhr.onerror = () => reject(new Error('Connection lost.'));
        xhr.onabort = () =>
            reject(new DOMException('Upload cancelled', 'AbortError'));
        xhr.onloadend = () => signal.removeEventListener('abort', abort);
        if (signal.aborted) {
            reject(new DOMException('Upload cancelled', 'AbortError'));
            return;
        }
        xhr.send(blob);
    });
}
