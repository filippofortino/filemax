import {
    Add01Icon,
    ArrowRight01Icon,
    File01Icon,
    ListViewIcon,
    Download01Icon,
    Globe02Icon,
} from '@hugeicons/core-free-icons';
import { HugeiconsIcon } from '@hugeicons/react';
import { Head, Link } from '@inertiajs/react';
import { Shell, TeamBadges } from '@/components/filemax';
import { Button } from '@/components/ui/button';
import { bytes, date } from '@/lib/format';
import type { Team, Transfer } from '@/lib/types';
import { home } from '@/routes';
import { index, show } from '@/routes/transfers';

type Props = {
    transfers: {
        data: Transfer[];
        links: { prev: string | null; next: string | null };
        meta: { current_page: number; last_page: number };
    };
    teams: Team[];
    filter: string;
    totals: {
        total: number;
        active: number;
        expired: number;
        downloads: number;
    };
};
export default function Index({ transfers, teams, filter, totals }: Props) {
    return (
        <Shell
            active="transfers"
            headerAction={
                <Button asChild className="history-new-transfer">
                    <Link href={home()} aria-label="New transfer">
                        <HugeiconsIcon
                            icon={Add01Icon}
                            size={16}
                            aria-hidden="true"
                        />
                        <span>New transfer</span>
                    </Link>
                </Button>
            }
        >
            <Head title="My transfers" />
            <main className="page-content history-page">
                <div className="page-heading">
                    <h1>My transfers</h1>
                    {totals.total > 0 && (
                        <p className="muted">
                            {totals.total} transfers · {totals.active} active ·{' '}
                            {totals.expired} expired
                        </p>
                    )}
                </div>
                {totals.total > 0 && (
                    <nav
                        aria-label="Filter transfers"
                        className="history-filters"
                    >
                        <Link
                            href={index({ query: { filter: 'all' } })}
                            className={`filter ${filter === 'all' ? 'active' : ''}`}
                            aria-current={filter === 'all' ? 'page' : undefined}
                        >
                            All transfers
                        </Link>
                        <Link
                            href={index({ query: { filter: 'public' } })}
                            className={`filter ${filter === 'public' ? 'active' : ''}`}
                            aria-current={
                                filter === 'public' ? 'page' : undefined
                            }
                        >
                            Public links
                        </Link>
                        {teams.length > 0 && (
                            <span
                                className="filter-divider"
                                aria-hidden="true"
                            />
                        )}
                        {teams.map((team) => (
                            <Link
                                key={team.id}
                                href={index({ query: { filter: team.id } })}
                                className={`filter ${filter === team.id ? 'active' : ''}`}
                                aria-current={
                                    filter === team.id ? 'page' : undefined
                                }
                            >
                                {team.name}
                            </Link>
                        ))}
                    </nav>
                )}
                {!transfers.data.length ? (
                    <div className="empty-state">
                        <span className="history-empty-icon">
                            <HugeiconsIcon
                                icon={ListViewIcon}
                                size={24}
                                aria-hidden="true"
                            />
                        </span>
                        <h2>
                            {totals.total === 0
                                ? 'No transfers yet'
                                : 'No transfers here yet'}
                        </h2>
                        <p className="muted">
                            {totals.total === 0
                                ? 'Drop some files on the New transfer page. Every link you create shows up here with its downloads and expiry.'
                                : 'Try another filter, or create a transfer to share with this audience.'}
                        </p>
                        <Button asChild>
                            <Link href={home()} aria-label="Create a transfer">
                                <HugeiconsIcon
                                    icon={Add01Icon}
                                    size={16}
                                    aria-hidden="true"
                                />
                                New transfer
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="transfer-list">
                        {transfers.data.map((transfer) => (
                            <Link
                                className={`transfer-list-item ${transfer.available ? '' : 'inactive'}`}
                                key={transfer.id}
                                href={show(transfer.id)}
                            >
                                <span className="history-file-icon">
                                    <HugeiconsIcon
                                        icon={File01Icon}
                                        size={20}
                                        aria-hidden="true"
                                    />
                                </span>
                                <div className="history-file-info">
                                    <h2>{transfer.title}</h2>
                                    <p className="muted">
                                        {transfer.files_count}{' '}
                                        {transfer.files_count === 1
                                            ? 'file'
                                            : 'files'}{' '}
                                        · {bytes(transfer.total_size)} ·{' '}
                                        {date(transfer.created_at)}
                                    </p>
                                </div>
                                <span className="history-audience">
                                    {transfer.visibility === 'public' ? (
                                        <span className="history-public">
                                            <HugeiconsIcon
                                                icon={Globe02Icon}
                                                size={13}
                                                aria-hidden="true"
                                            />
                                            Public
                                        </span>
                                    ) : transfer.teams.length ? (
                                        <TeamBadges
                                            teams={transfer.teams}
                                            limit={2}
                                        />
                                    ) : (
                                        <span className="text-destructive">
                                            Sharing needs repair
                                        </span>
                                    )}
                                </span>
                                <span
                                    className="history-downloads"
                                    title="Download-button clicks"
                                >
                                    {transfer.first_opened_at ||
                                    transfer.download_count ? (
                                        <>
                                            <HugeiconsIcon
                                                icon={Download01Icon}
                                                size={16}
                                                aria-hidden="true"
                                            />
                                            {transfer.download_count}
                                        </>
                                    ) : (
                                        <span className="muted">
                                            Not opened
                                        </span>
                                    )}
                                </span>
                                <span className="history-expiry muted">
                                    {transfer.revoked_at
                                        ? 'Deleted'
                                        : `${transfer.available ? 'Expires' : 'Expired'} ${date(transfer.expires_at)}`}
                                </span>
                                <HugeiconsIcon
                                    icon={ArrowRight01Icon}
                                    size={17}
                                    aria-hidden="true"
                                    className="history-arrow"
                                />
                            </Link>
                        ))}
                    </div>
                )}
                {transfers.meta?.last_page > 1 && (
                    <nav
                        aria-label="Pagination"
                        className="mt-6 flex items-center justify-between"
                    >
                        <Button
                            variant="outline"
                            disabled={!transfers.links.prev}
                            asChild={!!transfers.links.prev}
                        >
                            {transfers.links.prev ? (
                                <Link href={transfers.links.prev}>
                                    Previous
                                </Link>
                            ) : (
                                'Previous'
                            )}
                        </Button>
                        <span className="muted">
                            Page {transfers.meta.current_page} of{' '}
                            {transfers.meta.last_page}
                        </span>
                        <Button
                            variant="outline"
                            disabled={!transfers.links.next}
                            asChild={!!transfers.links.next}
                        >
                            {transfers.links.next ? (
                                <Link href={transfers.links.next}>Next</Link>
                            ) : (
                                'Next'
                            )}
                        </Button>
                    </nav>
                )}
            </main>
        </Shell>
    );
}
