export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    /**
     * Spatie role names assigned to the authenticated user. Used by the
     * sidebar to gate the Admin section. Always present (defaults to `[]`).
     */
    roles: string[];
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};
