export type PaginatorMeta = {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
};

export type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginator<T> = PaginatorMeta & {
    data: T[];
    links: PaginatorLink[];
    first_page_url?: string | null;
    last_page_url?: string | null;
    next_page_url?: string | null;
    prev_page_url?: string | null;
    path?: string;
};

export type SortState = {
    column: string;
    direction: 'asc' | 'desc';
};
