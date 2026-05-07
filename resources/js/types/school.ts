/**
 * Tenant registry row served from `pas_schools`. The shape mirrors the
 * `App\Models\Pas\School` model fillable plus its key + timestamps.
 *
 * `lms_db_password` round-trips through Laravel's `encrypted` cast — when
 * the model is serialized into Inertia props, the field surfaces in
 * plaintext. Acceptable here because every page that receives a `School`
 * is super-admin gated by `SchoolPolicy`.
 */
export interface School {
    id: number;
    name: string;
    slug: string;
    domain: string | null;
    lms_db_host: string;
    lms_db_port: number;
    lms_db_database: string;
    lms_db_username: string;
    lms_db_password: string;
    lms_db_charset: string;
    is_active: boolean;
    created_at: string;
    updated_at: string;
}

/**
 * Shape posted by the create/edit form. `lms_db_port` is permitted as a
 * string in the client-side state so the controlled `<Input>` can hold an
 * intermediate empty value while typing — the server casts it via the
 * `integer` rule on store/update.
 *
 * On edit, `lms_db_password` ships as `''` to mean "keep current"; the
 * controller drops the key from the update payload when empty.
 */
export interface SchoolFormData {
    name: string;
    slug: string;
    domain: string;
    lms_db_host: string;
    lms_db_port: number | string;
    lms_db_database: string;
    lms_db_username: string;
    lms_db_password: string;
    lms_db_charset: string;
    is_active: boolean;
}

/** Shape returned by `POST /admin/schools/test-connection`. */
export interface ConnectionTestResult {
    ok: boolean;
    message: string;
}
