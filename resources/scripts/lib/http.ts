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
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = body.errors
            ? Object.values(body.errors).flat().join(' ')
            : body.message;
        throw new Error(
            message ||
                (response.status === 419
                    ? 'Your session expired. Refresh the page and sign in again.'
                    : 'This request could not be completed. Please try again.'),
        );
    }
    return body as T;
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
                : reject(
                      new Error(
                          'Upload interrupted. Retry sends only what is missing.',
                      ),
                  );
        xhr.onerror = () =>
            reject(
                new Error('Connection lost. Your completed files are safe.'),
            );
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
