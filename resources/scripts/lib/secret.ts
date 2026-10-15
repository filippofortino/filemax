// Secrets are encrypted and decrypted only in the browser. Filemax stores the ciphertext and a
// hash of a verifier derived from the key, so it can count views without ever holding the key.
const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const encoder = new TextEncoder();

function base64(bytes: Uint8Array): string {
    return btoa(
        Array.from(bytes, (byte) => String.fromCharCode(byte)).join(''),
    );
}

/** 20 Crockford base32 characters: 100 random bits, short enough to read out over the phone. */
function createKey(): string {
    return Array.from(
        crypto.getRandomValues(new Uint8Array(20)),
        (byte) => alphabet[byte & 31],
    ).join('');
}

export function formatKey(key: string): string {
    return key.match(/.{1,4}/g)?.join('-') ?? key;
}

/** Accepts the key with or without dashes, spaces or case, or a whole link that carries it after #. */
export function normaliseKey(input: string): string | null {
    const key = (input.split('#').pop() ?? '')
        .toUpperCase()
        .replace(/O/g, '0')
        .replace(/[IL]/g, '1')
        .replace(/[^0-9A-Z]/g, '');
    return /^[0-9A-HJKMNP-TV-Z]{20}$/.test(key) ? key : null;
}

/** Derives the AES-GCM key and the server-side verifier from the same key, independently. */
export async function secretKeys(key: string) {
    const material = await crypto.subtle.importKey(
        'raw',
        encoder.encode(key),
        'HKDF',
        false,
        ['deriveKey', 'deriveBits'],
    );
    const hkdf = (info: string): HkdfParams => ({
        name: 'HKDF',
        hash: 'SHA-256',
        salt: new Uint8Array(),
        info: encoder.encode(info),
    });
    const [encryptionKey, verifierBits] = await Promise.all([
        crypto.subtle.deriveKey(
            hkdf('filemax-secret-encryption'),
            material,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt'],
        ),
        crypto.subtle.deriveBits(
            hkdf('filemax-secret-verifier'),
            material,
            256,
        ),
    ]);
    return {
        verifier: base64(new Uint8Array(verifierBits))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/, ''),
        async decrypt(payload: string): Promise<string> {
            const bytes = Uint8Array.from(atob(payload), (char) =>
                char.charCodeAt(0),
            );
            const padded = new Uint8Array(
                await crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: bytes.slice(0, 12) },
                    encryptionKey,
                    bytes.slice(12),
                ),
            );
            return new TextDecoder().decode(
                padded.subarray(0, padded.lastIndexOf(0x80)),
            );
        },
        async encrypt(text: string): Promise<string> {
            // Pad to 256-byte blocks (0x80 then zeros) so the ciphertext doesn't reveal the exact length.
            const bytes = encoder.encode(text);
            const padded = new Uint8Array(
                Math.ceil((bytes.length + 1) / 256) * 256,
            );
            padded.set(bytes);
            padded[bytes.length] = 0x80;
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const ciphertext = new Uint8Array(
                await crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv },
                    encryptionKey,
                    padded,
                ),
            );
            const payload = new Uint8Array(iv.length + ciphertext.length);
            payload.set(iv);
            payload.set(ciphertext, iv.length);
            return base64(payload);
        },
    };
}

export async function encryptSecret(
    text: string,
): Promise<{ key: string; payload: string; verifier: string }> {
    const key = createKey();
    const keys = await secretKeys(key);
    return { key, payload: await keys.encrypt(text), verifier: keys.verifier };
}
