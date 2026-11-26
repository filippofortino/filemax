/**
 * Christmas decorations, up from 26 Nov to 6 Jan.
 *
 * Everything seasonal lives here: this folder, public/favicon-christmas.svg and
 * tests/Browser/ChristmasBrowserTest.php. To take it out for good, delete those,
 * remove the @import in resources/css/app.css, and drop the few usages the
 * compiler then points at (search for "@/christmas").
 *
 * Out of season, open any page with ?christmas=on to preview (?christmas=off stops).
 * Grinch Mode and the preview are remembered per browser, so nothing touches the server.
 */
import { PackageIcon, SnowflakeIcon } from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { useSyncExternalStore, type CSSProperties } from 'react';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import grinchPeeking from './grinch.webp';

/** "stealing" and "returning" are the moments the hat leaves or comes back when Grinch Mode flips. */
type Mode = 'off' | 'on' | 'stealing' | 'grinch' | 'returning';

const PREVIEW = 'filemax:christmas-preview';
const GRINCH = 'filemax:grinch-mode';
const listeners = new Set<() => void>();
let transition: 'stealing' | 'returning' | null = null;
let settle: ReturnType<typeof setTimeout> | undefined;

function inSeason(): boolean {
    const now = new Date();
    return (
        (now.getMonth() === 10 && now.getDate() >= 26) ||
        now.getMonth() === 11 ||
        (now.getMonth() === 0 && now.getDate() <= 6)
    );
}

/** Choices storage refused (private mode, blocked site data). They last until the page is left. */
const unsaved = new Map<string, boolean>();

function flag(key: string): boolean {
    const choice = unsaved.get(key);
    if (choice !== undefined) return choice;
    try {
        return localStorage.getItem(key) !== null;
    } catch {
        return false; // No storage (server render, private mode): fall back to the calendar.
    }
}

function setFlag(key: string, on: boolean) {
    try {
        if (on) localStorage.setItem(key, '1');
        else localStorage.removeItem(key);
        unsaved.delete(key);
    } catch {
        unsaved.set(key, on);
    }
}

/** Automated browsers (the test suite) see the plain app unless they ask for the preview, so tests don't depend on the date. */
const automated = () => typeof navigator !== 'undefined' && navigator.webdriver;

function mode(): Mode {
    if ((automated() || !inSeason()) && !flag(PREVIEW)) return 'off';
    return transition ?? (flag(GRINCH) ? 'grinch' : 'on');
}

const festive = (current: Mode) => current === 'on' || current === 'returning';

function changed() {
    const icon = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    const href = icon?.href.replace(
        /favicon(-christmas)?\.svg/,
        festive(mode()) ? 'favicon-christmas.svg' : 'favicon.svg',
    );
    if (icon && href && href !== icon.href) icon.href = href;
    listeners.forEach((listener) => listener());
}

function setGrinch(on: boolean) {
    setFlag(GRINCH, on);
    transition = on ? 'stealing' : 'returning';
    clearTimeout(settle);
    // Matches xmas-hat-stolen and xmas-hat-back in christmas.css.
    settle = setTimeout(
        () => {
            transition = null;
            changed();
        },
        on ? 1400 : 500,
    );
    changed();
}

if (typeof window !== 'undefined') {
    const preview = new URLSearchParams(window.location.search).get(
        'christmas',
    );
    if (preview) setFlag(PREVIEW, preview === 'on');
    window.addEventListener('storage', changed);
    changed();
}

function useMode(): Mode {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        mode,
        () => (inSeason() ? 'on' : 'off'),
    );
}

/** True while the decorations are up for this visitor. Use it for seasonal copy and icons. */
export function useChristmas(): boolean {
    return festive(useMode());
}

/** Goes inside the logo link: a Santa hat on the mark and a "Ho ho ho!" on hover. */
export function BrandDecoration({ large = false }: { large?: boolean }) {
    const current = useMode();
    if (current === 'off' || current === 'grinch') return null;
    return (
        <>
            <svg
                className={cn(
                    'xmas-hat',
                    large && 'xmas-large',
                    current === 'stealing' && 'xmas-hat-stolen',
                    current === 'returning' && 'xmas-hat-back',
                )}
                viewBox="0 0 20 16"
                fill="none"
                aria-hidden="true"
            >
                <path
                    d="M3 12C4 6.5 8 2.5 13 2.2C16 2 17.4 3.6 17.2 6.4L16.4 12Z"
                    fill="#D92D20"
                />
                <rect
                    x="1.2"
                    y="10.6"
                    width="16.8"
                    height="4.2"
                    rx="2.1"
                    fill="#FFFFFF"
                    stroke="#CBD5E1"
                    strokeWidth="0.8"
                />
                <circle
                    cx="17.3"
                    cy="6.4"
                    r="2.2"
                    fill="#FFFFFF"
                    stroke="#CBD5E1"
                    strokeWidth="0.8"
                />
            </svg>
            {current === 'stealing' ? (
                <svg
                    className="xmas-arm"
                    viewBox="0 0 22 60"
                    fill="none"
                    aria-hidden="true"
                >
                    <path d="M7 0 7.5 42Q11 47 14.5 42L15 0Z" fill="#4D9A2A" />
                    <path
                        d="M7.5 43Q5 50 6.5 57M11 45V58.5M14.5 43Q17 50 15.5 57"
                        stroke="#4D9A2A"
                        strokeWidth="3"
                        strokeLinecap="round"
                    />
                    <path
                        d="M7 12 5 14.5M15 22 17 24.5M7.3 31 5.3 33.5M14.8 36 16.8 38.5"
                        stroke="#3B7A1E"
                        strokeWidth="1.2"
                        strokeLinecap="round"
                    />
                </svg>
            ) : (
                <span
                    aria-hidden="true"
                    className={cn(
                        // Covers the word "Filemax": everything right of the mark and the gap after it.
                        'xmas-ho pointer-events-none absolute inset-y-0 right-0 flex items-center justify-center gap-[0.25em] text-[0.68em] leading-none tracking-normal whitespace-nowrap text-[#b42318]',
                        large ? 'left-11' : 'left-8 md:left-8.5',
                    )}
                >
                    <span>Ho</span>
                    <span>ho</span>
                    <span>ho!</span>
                </span>
            )}
        </>
    );
}

/** left %, top %, size px, drift seconds, delay seconds */
const flakes = [
    [6, 8, 14, 9, -2],
    [16, 30, 10, 11, -6],
    [9, 58, 18, 10, -3],
    [20, 80, 12, 12, -8],
    [30, 12, 10, 8, -5],
    [70, 10, 12, 10, -1],
    [82, 26, 16, 9, -7],
    [92, 50, 10, 11, -4],
    [78, 70, 14, 12, -9],
    [88, 88, 12, 8, -2],
    [46, 90, 10, 10, -6],
    [56, 6, 12, 11, -3],
] as const;

/** Snow drifting behind whatever else is in its parent. */
export function Snow() {
    if (!useChristmas()) return null;
    return (
        <div
            aria-hidden="true"
            className="xmas-snow pointer-events-none absolute inset-0 -z-10 text-primary/30"
        >
            {flakes.map(([left, top, size, duration, delay]) => (
                <HugeiconsIcon
                    key={`${left}-${top}`}
                    icon={SnowflakeIcon}
                    size={size}
                    strokeWidth={1.8}
                    className="xmas-flake absolute"
                    style={{
                        left: `${left}%`,
                        top: `${top}%`,
                        animationDuration: `${duration}s`,
                        animationDelay: `${delay}s`,
                    }}
                />
            ))}
        </div>
    );
}

/** x px, y px, size px: where each flake lands, measured from the centre. */
const puff = [
    [-62, -26, 12],
    [-48, 30, 10],
    [-82, 4, 9],
    [58, -30, 12],
    [78, 8, 10],
    [46, 34, 9],
    [-20, -54, 8],
    [24, -52, 10],
] as const;

/** A burst of snowflakes out of the centre of its parent, placed right after the parent's icon. */
export function SnowPuff() {
    if (!useChristmas()) return null;
    return (
        <span
            aria-hidden="true"
            className="xmas-puff pointer-events-none absolute inset-0"
        >
            {puff.map(([x, y, size]) => (
                <HugeiconsIcon
                    key={x}
                    icon={SnowflakeIcon}
                    size={size}
                    strokeWidth={1.8}
                    className="xmas-pop absolute top-1/2 left-1/2"
                    style={
                        { '--dx': `${x}px`, '--dy': `${y}px` } as CSSProperties
                    }
                />
            ))}
        </span>
    );
}

/** x, y, tilt degrees, colour, flicker seconds, delay seconds along one 390px stretch of wire */
const bulbs = [
    [17, 6.5, -24, '#D92D20', 3.7, 0],
    [52.5, 9.5, 14, '#F59E0B', 5.3, -2.1],
    [93.5, 5.5, -8, '#2140E0', 4.1, -0.7],
    [132.5, 10.5, 30, '#16A34A', 6.1, -3.4],
    [173, 7.5, -18, '#DB2777', 4.7, -1.3],
    [216.5, 11.5, 6, '#F59E0B', 3.3, -4.2],
    [253, 6.5, -32, '#D92D20', 5.9, -0.4],
    [293.5, 9.5, 20, '#16A34A', 4.3, -2.8],
    [334.5, 5.5, -10, '#2140E0', 6.7, -1.9],
    [371, 8.5, 26, '#DB2777', 3.9, -3.1],
] as const;

type BulbAt = readonly [number, number, number, string, number, number];

function Bulb({
    bulb: [x, y, tilt, color, duration, delay],
}: {
    bulb: BulbAt;
}) {
    return (
        <g transform={`translate(${x} ${y}) rotate(${tilt})`}>
            <rect x="-1.6" width="3.2" height="2.8" rx="0.6" fill="#1F3B2C" />
            <path
                className="xmas-bulb"
                d="M-1.5 2.6C-3.5 4.6-2.7 8.6 0 11C2.7 8.6 3.5 4.6 1.5 2.6Z"
                fill="currentColor"
                style={{
                    color,
                    animationDuration: `${duration}s`,
                    animationDelay: `${delay}s`,
                }}
            />
        </g>
    );
}

/** A string of lights across a phone screen, hanging from wherever it is placed (right under the header). From md up the card wears them instead, see CardLights. */
export function StringLights() {
    if (!useChristmas()) return null;
    return (
        <div
            aria-hidden="true"
            className="pointer-events-none relative z-10 h-0 md:hidden"
        >
            <div className="absolute inset-x-0 -top-0.5 flex h-8 justify-center overflow-hidden">
                {/* The wire starts and ends at the same height, so stretches tile; eight cover a 3000px screen. */}
                {Array.from({ length: 8 }, (_, stretch) => (
                    <svg
                        key={stretch}
                        width="390"
                        height="28"
                        viewBox="0 0 390 28"
                        fill="none"
                        className="shrink-0 overflow-visible"
                    >
                        <path
                            d="M0 2 Q17 12.5 34 1 Q52.5 18 71 3 Q93.5 10 116 1 Q132.5 20.5 149 2 Q173 14.5 197 1 Q216.5 22 236 3 Q253 12 270 1 Q293.5 18.5 317 2 Q334.5 10.5 352 1 Q371 16.5 390 2"
                            stroke="#1F3B2C"
                            strokeWidth="1.3"
                            strokeLinecap="round"
                        />
                        <path
                            d="M0 2 Q17 7.5 34 1 Q52.5 21 71 3 Q93.5 6 116 1 Q132.5 24.5 149 2 Q173 8.5 197 1 Q216.5 24 236 3 Q253 9 270 1 Q293.5 22.5 317 2 Q334.5 6.5 352 1 Q371 19.5 390 2"
                            stroke="#1F3B2C"
                            strokeWidth="0.9"
                            strokeLinecap="round"
                            opacity="0.7"
                        />
                        {bulbs.map((bulb) => (
                            <Bulb key={bulb[0]} bulb={bulb} />
                        ))}
                    </svg>
                ))}
            </div>
        </div>
    );
}

/** One swag of wire between two nails, at the size it has on a 576px card. */
const SWAG = 185.33;
const colors = ['#D92D20', '#F59E0B', '#2140E0', '#16A34A', '#DB2777'];
const flickers = [3.7, 5.3, 4.1, 6.1, 4.7, 3.3, 5.9, 4.3, 6.7];

/** Lights draped from the top edge of the card it is placed in, from md up where pages have no header bar to hang them from. Three swags span a 576px card; pass more for a wider one. */
export function CardLights({ swags = 3 }: { swags?: number }) {
    if (!useChristmas()) return null;
    const nails = Array.from(
        { length: swags + 1 },
        (_, index) => 10 + index * SWAG,
    );
    const wire = (sag: number) =>
        `M10 1${nails
            .slice(1)
            .map((x) => ` Q${x - SWAG / 2} ${sag} ${x} 1`)
            .join('')}`;
    // Three bulbs per swag, the middle one lowest, tilted along the wire.
    const bulbs = nails.slice(0, -1).flatMap((x, swag) =>
        [
            [37.1, 15.4, 8],
            [92.7, 23.5, 0],
            [148.2, 15.4, -8],
        ].map(([dx, y, tilt], index): BulbAt => {
            const n = swag * 3 + index;
            return [
                x + dx,
                y,
                tilt,
                colors[n % 5],
                flickers[n % 9],
                -((n * 1.3) % 4.2),
            ];
        }),
    );
    return (
        <svg
            aria-hidden="true"
            viewBox={`0 0 ${20 + swags * SWAG} 40`}
            fill="none"
            className="xmas-card-lights pointer-events-none absolute -top-px -left-px hidden w-[calc(100%+2px)] overflow-visible md:block"
        >
            <path
                d={wire(46)}
                stroke="#1F3B2C"
                strokeWidth="1.3"
                strokeLinecap="round"
            />
            <path
                d={wire(38)}
                stroke="#1F3B2C"
                strokeWidth="0.9"
                strokeLinecap="round"
                opacity="0.7"
            />
            {nails.map((x) => (
                <circle key={x} cx={x} cy="1" r="2.4" fill="#1F3B2C" />
            ))}
            {bulbs.map((bulb) => (
                <Bulb key={bulb[0]} bulb={bulb} />
            ))}
        </svg>
    );
}

/** The settings card with the Grinch Mode switch. Shows only while the season (or a preview) is on. */
export function ChristmasSettings() {
    const current = useMode();
    if (current === 'off') return null;
    const grinch = current === 'grinch' || current === 'stealing';
    return (
        <section
            aria-labelledby="christmas-title"
            className="flex flex-col gap-5 rounded-xl border bg-background p-6"
        >
            <div className="flex flex-col gap-1.5">
                <h2 id="christmas-title" className="text-xl">
                    Christmas
                </h2>
                <p className="text-sm text-muted-foreground">
                    From 26 Nov to 6 Jan Filemax puts on a hat, some snow and a
                    string of lights.
                </p>
            </div>
            <div className="flex items-start justify-between gap-6">
                <div className="flex flex-col gap-1">
                    <span id="grinch-label" className="font-semibold">
                        Grinch Mode
                    </span>
                    <p
                        id="grinch-hint"
                        className="text-sm text-muted-foreground"
                    >
                        Turn it on to take the decorations down: no hat, no
                        snow, no lights. It only changes what you see in this
                        browser. People opening your links keep theirs.
                    </p>
                </div>
                <Switch
                    checked={grinch}
                    className="data-checked:bg-green-600"
                    aria-labelledby="grinch-label"
                    aria-describedby="grinch-hint"
                    onCheckedChange={setGrinch}
                />
            </div>
            <div
                className={cn(
                    'relative mt-7.5 flex items-center gap-3 rounded-lg bg-muted px-4 py-3 transition-colors duration-200 ease-out',
                    grinch && 'bg-green-600/12',
                )}
            >
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute right-4 bottom-full h-16.5 w-22 overflow-hidden"
                >
                    {/* Pops up from behind the strip once the hat is gone (the steal takes 1.4s). */}
                    <img
                        src={grinchPeeking}
                        alt=""
                        width={88}
                        height={66}
                        className={cn(
                            'block size-full translate-y-full transition-transform duration-250 ease-in',
                            grinch &&
                                'translate-y-0 delay-1100 duration-500 ease-[cubic-bezier(0.34,1.56,0.64,1)]',
                        )}
                    />
                </span>
                <span
                    aria-hidden="true"
                    className={cn(
                        'inline-flex size-9 shrink-0 items-center justify-center rounded-full border border-blue-200 bg-blue-50 text-primary',
                        grinch &&
                            'border-transparent bg-green-600/15 text-green-800',
                    )}
                >
                    <HugeiconsIcon
                        icon={grinch ? PackageIcon : SnowflakeIcon}
                        size={18}
                    />
                </span>
                <div className="flex flex-col gap-0.5" aria-live="polite">
                    <span className="text-xs text-muted-foreground">
                        Right now
                    </span>
                    <span
                        className={cn(
                            'text-sm font-semibold',
                            grinch && 'text-green-800',
                        )}
                    >
                        {grinch
                            ? 'Decorations packed away. Bah, humbug.'
                            : 'Decorations are up'}
                    </span>
                </div>
            </div>
        </section>
    );
}
