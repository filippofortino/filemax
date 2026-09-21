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
