export type Team = { id: string; name: string; users_count: number };
export type User = {
    id: string;
    name: string;
    email: string;
    is_admin: boolean;
    email_verified_at: string | null;
};
export type SharedProps = {
    auth: { user: User | null };
    status?: string;
    csrf_token: string;
    [key: string]: unknown;
};
export type TransferFile = {
    id: string;
    original_name: string;
    size: number;
    mime_type: string;
    status: string;
    part_size: number;
    position: number;
    download_count: number;
};
export type Transfer = {
    id: string;
    token: string;
    title: string;
    message: string | null;
    visibility: 'public' | 'teams';
    status: string;
    files: TransferFile[];
    teams: Team[];
    total_size: number;
    files_count: number;
    download_count: number;
    first_opened_at: string | null;
    last_downloaded_at: string | null;
    expires_at: string | null;
    created_at: string;
    revoked_at: string | null;
    available: boolean;
    url: string;
};
