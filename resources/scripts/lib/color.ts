/** A six-digit hex color with its #, the only kind a brand color can be. */
export function isHexColor(value: string): boolean {
    return /^#[0-9a-f]{6}$/i.test(value);
}

/** WCAG contrast against white. Mirrors Organization::contrastWithWhite(), which has the final say on save. */
export function contrastWithWhite(hex: string): number {
    const channel = (part: string) => {
        const value = parseInt(part, 16) / 255;
        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    };
    const luminance =
        0.2126 * channel(hex.slice(1, 3)) +
        0.7152 * channel(hex.slice(3, 5)) +
        0.0722 * channel(hex.slice(5, 7));
    return 1.05 / (luminance + 0.05);
}
