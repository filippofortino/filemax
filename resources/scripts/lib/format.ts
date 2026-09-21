export function bytes(value: number): string {
    if (!value) return '0 B';
    const unit = Math.min(Math.floor(Math.log(value) / Math.log(1000)), 5);
    return `${new Intl.NumberFormat('en-GB', { maximumFractionDigits: 2 }).format(value / 1000 ** unit)} ${['B', 'KB', 'MB', 'GB', 'TB', 'PB'][unit]}`;
}
export function date(value: string | null): string {
    return value
        ? new Intl.DateTimeFormat('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          }).format(new Date(value))
        : '—';
}
export function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .map((part) => part[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}
