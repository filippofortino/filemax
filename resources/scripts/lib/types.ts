export type Organization = {
    id: string;
    name: string;
    personal: boolean;
    logo_url: string | null;
    role: 'admin' | 'member';
    members_count: number;
    current: boolean;
};
export type User = {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
    is_admin: boolean;
    email_verified_at: string | null;
};
export type SharedProps = {
    auth: { user: User | null; organizations: Organization[] };
    passwordRequirements: {
        min: number;
        mixedCase: boolean;
        numbers: boolean;
        symbols: boolean;
    };
    status?: string;
    [key: string]: unknown;
};
declare module '@inertiajs/core' {
    interface InertiaConfig {
        flashDataType: {
            toast?: {
                type?: 'success' | 'error';
                title: string;
                description?: string;
            };
        };
    }
}
export type TransferFile = {
    id: string;
    original_name: string;
    folder: string | null;
    size: number;
    mime_type: string;
    status: string;
    part_size: number;
    position: number;
    download_count: number;
    preview_count: number;
};
export type Transfer = {
    id: string;
    token: string;
    title: string;
    message: string | null;
    visibility: 'public' | 'members';
    /** The organization it was sent from, or null when sent from Personal. */
    organization: string | null;
    password_protected: boolean;
    status: string;
    files: TransferFile[];
    total_size: number;
    files_count: number;
    download_count: number;
    first_opened_at: string | null;
    last_downloaded_at: string | null;
    preview_count: number;
    last_previewed_at: string | null;
    expires_at: string | null;
    created_at: string;
    revoked_at: string | null;
    available: boolean;
    can_extend: boolean;
    retained_until: string | null;
    expiring_soon: boolean;
    expires_in: string | null;
    url: string;
};
/** Someone the transfer was emailed to, or a signed-in member, with what they did. */
export type TransferPerson = {
    email: string;
    /** Set for members, who are shown by name. */
    name: string | null;
    sent_at: string | null;
    last_active_at: string | null;
    activity: {
        kind: 'opened' | 'previewed' | 'downloaded';
        file_id: string | null;
        folder: string | null;
        times: number;
        last_at: string;
    }[];
};
export type Secret = {
    id: string;
    title: string;
    labelled: boolean;
    created_at: string;
    expires_at: string;
    max_views: number;
    view_count: number;
    last_opened_at: string | null;
    wrong_key_count: number;
    last_wrong_key_at: string | null;
    revoked_at: string | null;
    available: boolean;
    expiring_soon: boolean;
    expires_in: string | null;
    reason: 'opened' | 'expired' | 'deleted' | null;
};
export type DropFileType = 'documents' | 'images' | 'videos';
/** What a Drop accepts. A null size means no limit beyond Filemax's own. */
export type DropRules = {
    max_file_size: number | null;
    max_submission_size: number | null;
    file_types: DropFileType[];
    extensions: string[];
};
export type Drop = DropRules & {
    id: string;
    title: string;
    instructions: string | null;
    url: string;
    open: boolean;
    closes_at: string;
    closed_at: string;
    closed_early: boolean;
    keep_days: number;
    uploads_count: number;
    created_at: string;
};
export type ReceivedTransfer = {
    id: string;
    token: string | null;
    sender_name: string | null;
    title: string;
    message: string | null;
    drop: {
        id: string;
        title: string;
        open: boolean;
        closes_at: string;
        closed_at: string;
        closed_early: boolean;
    } | null;
    files: TransferFile[];
    files_count: number;
    total_size: number;
    keep_days: number;
    received_at: string | null;
    expires_at: string | null;
    available: boolean;
    gone_at: string | null;
    is_new: boolean;
};
