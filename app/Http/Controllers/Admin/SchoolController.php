<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSchoolRequest;
use App\Http\Requests\Admin\TestSchoolConnectionRequest;
use App\Http\Requests\Admin\UpdateSchoolRequest;
use App\Models\Pas\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use PDO;
use Throwable;

/**
 * Admin surface for the multi-tenant `/admin/schools` registry (Phase B.2).
 *
 * Super-admin only — every action gates via SchoolPolicy. The registry is
 * the source of truth for per-tenant LMS connection credentials; Phase C's
 * SwitchLmsConnection task reads from these rows to rebind
 * `config('database.connections.lms')` per request.
 *
 * The default school (slug = 'default') is non-deletable: it backstops the
 * Phase D `school_id` backfill on every existing pas_* row and is what
 * Phase C's tenant finder falls through to when no subdomain or path-prefix
 * matches. Removing it would orphan all existing single-tenant data.
 *
 * `lms_db_password` round-trips through the model's `encrypted` cast — when
 * the edit form receives the school model the field surfaces in plaintext.
 * Acceptable here because the page is super-admin only; revisit when
 * B.2-frontend hits UAT (mask + "click to reveal" are reasonable v2).
 *
 * `testConnection()` opens a transient connection slot named `_tenant_test`
 * (leading underscore signals "do not use for application reads"), runs
 * `SELECT 1`, and returns JSON. The connection is purged on every exit
 * path so a failed test never leaves a stale config behind. Errors are
 * scrubbed of the password before return so a verbose driver message
 * doesn't echo credentials back to the browser.
 */
final class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', School::class);

        $search = trim((string) $request->query('search', ''));
        $statusRaw = (string) $request->query('status', 'all');
        $status = in_array($statusRaw, ['active', 'inactive', 'all'], true)
            ? $statusRaw
            : 'all';

        $schools = School::query()
            ->when($search !== '', function ($q) use ($search): void {
                $term = '%'.$search.'%';
                $q->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhere('domain', 'like', $term);
                });
            })
            ->when($status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('admin/schools/index', [
            'schools' => $schools,
            'filters' => [
                'search' => $search,
                'status' => $status,
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', School::class);

        return Inertia::render('admin/schools/form', [
            'school' => null,
            'mode' => 'create',
        ]);
    }

    public function store(StoreSchoolRequest $request): RedirectResponse
    {
        Gate::authorize('create', School::class);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $school = DB::transaction(fn (): School => School::create($data));

        return redirect()
            ->route('admin.schools.index')
            ->with('success', "School '{$school->name}' created.");
    }

    public function edit(School $school): Response
    {
        Gate::authorize('update', $school);

        return Inertia::render('admin/schools/form', [
            'school' => $school,
            'mode' => 'edit',
        ]);
    }

    public function update(UpdateSchoolRequest $request, School $school): RedirectResponse
    {
        Gate::authorize('update', $school);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // "Leave password blank to keep current" — drop the key entirely
        // when empty so $school->update() doesn't overwrite the column.
        if (! array_key_exists('lms_db_password', $data)
            || $data['lms_db_password'] === null
            || $data['lms_db_password'] === ''
        ) {
            unset($data['lms_db_password']);
        }

        DB::transaction(fn () => $school->update($data));

        return redirect()
            ->route('admin.schools.index')
            ->with('success', "School '{$school->name}' updated.");
    }

    public function destroy(School $school): RedirectResponse
    {
        Gate::authorize('delete', $school);

        // The default school backstops the Phase D `school_id` backfill
        // and Phase C's tenant fall-through. Deleting it would orphan
        // every existing pas_* row.
        if ($school->slug === 'default') {
            abort(403, 'The default school cannot be deleted.');
        }

        $name = $school->name;
        DB::transaction(fn () => $school->delete());

        return redirect()
            ->route('admin.schools.index')
            ->with('success', "School '{$name}' deleted.");
    }

    /**
     * Phase E preview — pin the active tenant to the chosen school for this
     * super-admin's session. SchoolTenantFinder reads this override BEFORE
     * its domain/path/header strategies, gated by a super-admin role check
     * so a leaked session key cannot bypass tenant boundaries.
     */
    public function switchTenant(Request $request, School $school): RedirectResponse
    {
        Gate::authorize('viewAny', School::class);

        if (! $school->is_active) {
            abort(422, 'Cannot switch to an inactive school.');
        }

        $request->session()->put('current_school_id_override', $school->id);

        return redirect()
            ->back()
            ->with('success', "Switched to '{$school->name}'.");
    }

    /**
     * Drop the session-stored tenant override. Resolution falls back to the
     * normal domain/path/header strategies on the next request.
     */
    public function clearSwitch(Request $request): RedirectResponse
    {
        Gate::authorize('viewAny', School::class);

        $request->session()->forget('current_school_id_override');

        return redirect()
            ->back()
            ->with('success', 'Cleared school override.');
    }

    public function testConnection(TestSchoolConnectionRequest $request): JsonResponse
    {
        /** @var array{lms_db_host: string, lms_db_port: int, lms_db_database: string, lms_db_username: string, lms_db_password: string, lms_db_charset: string} $payload */
        $payload = $request->validated();

        config(['database.connections._tenant_test' => [
            'driver' => 'mysql',
            'host' => $payload['lms_db_host'],
            'port' => (int) $payload['lms_db_port'],
            'database' => $payload['lms_db_database'],
            'username' => $payload['lms_db_username'],
            'password' => $payload['lms_db_password'],
            'charset' => $payload['lms_db_charset'],
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => [
                PDO::ATTR_TIMEOUT => 5,
            ],
        ]]);

        // Purge any stale resolved instance under this slot before we
        // attempt to connect with the new config.
        DB::purge('_tenant_test');

        try {
            $result = DB::connection('_tenant_test')->select('SELECT 1 AS probe');
            $ok = isset($result[0]->probe) && (int) $result[0]->probe === 1;
            DB::purge('_tenant_test');

            return response()->json([
                'ok' => $ok,
                'message' => $ok ? 'Connection successful' : 'Connection probe returned unexpected result',
            ]);
        } catch (Throwable $e) {
            DB::purge('_tenant_test');

            $msg = $e->getMessage();
            if ($payload['lms_db_password'] !== '' && str_contains($msg, $payload['lms_db_password'])) {
                $msg = str_replace($payload['lms_db_password'], '***', $msg);
            }

            // 200, not 4xx — the request succeeded; the connection failed.
            // The frontend distinguishes via the `ok` flag.
            return response()->json([
                'ok' => false,
                'message' => $msg,
            ], 200);
        }
    }
}
