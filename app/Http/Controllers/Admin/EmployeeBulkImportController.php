<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exports\EmployeeBulkEditExport;
use App\Http\Controllers\Controller;
use App\Imports\EmployeeBulkEditImport;
use App\Models\Pas\AuditLog;
use App\Models\Pas\EmployeeProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 3 W12 Stage A — bulk-edit employee profiles via Excel.
 *
 * Three-step flow:
 *   1. GET /admin/employees/import        → upload form (Inertia)
 *   2. POST /admin/employees/import/preview → parses + validates, stashes
 *      the parsed rows in the session keyed by a UUID, redirects to a
 *      preview page that shows the per-row diff + any errors.
 *   3. POST /admin/employees/import/confirm/{token} → re-reads the
 *      stashed parsed rows, applies them in one DB::transaction, and
 *      writes a single composite audit-log entry capturing the full
 *      changeset.
 *
 * The session-token bridge keeps Excel parsing off the request thread
 * for confirm — useful if we later move parsing to a queued job.
 */
final class EmployeeBulkImportController extends Controller
{
    public function index(): Response
    {
        if (! auth()->user()?->hasRole('super-admin')) {
            abort(403);
        }

        return Inertia::render('admin/employees/import/index', [
            'parsed' => session('employee_bulk_import.parsed'),
            'token' => session('employee_bulk_import.token'),
            'sourceFilename' => session('employee_bulk_import.source_filename'),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        if (! auth()->user()?->hasRole('super-admin')) {
            abort(403);
        }

        return Excel::download(
            new EmployeeBulkEditExport,
            'employees-bulk-edit-template.xlsx',
        );
    }

    public function preview(Request $request): RedirectResponse
    {
        if (! auth()->user()?->hasRole('super-admin')) {
            abort(403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        $import = new EmployeeBulkEditImport;
        Excel::import($import, $request->file('file'));

        $token = (string) Str::uuid();

        session([
            'employee_bulk_import.parsed' => $import->parsed(),
            'employee_bulk_import.token' => $token,
            'employee_bulk_import.source_filename' => $request->file('file')?->getClientOriginalName(),
        ]);

        return redirect()->route('admin.employees.import.index');
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        if (! auth()->user()?->hasRole('super-admin')) {
            abort(403);
        }

        if (session('employee_bulk_import.token') !== $token) {
            return redirect()
                ->route('admin.employees.import.index')
                ->withErrors(['token' => 'Preview is no longer valid. Re-upload the file.']);
        }

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('employee_bulk_import.parsed');
        if ($parsed === null) {
            return redirect()
                ->route('admin.employees.import.index')
                ->withErrors(['token' => 'No parsed rows in session. Upload again.']);
        }

        $applicable = array_filter(
            $parsed,
            static fn (array $r): bool => empty($r['errors']) && ! empty($r['changes']),
        );

        if (count($applicable) === 0) {
            session()->forget('employee_bulk_import');

            return redirect()
                ->route('employees.index')
                ->with('warning', 'No applicable changes were found. Nothing was imported.');
        }

        $applied = [];
        DB::transaction(function () use ($applicable, &$applied, $request): void {
            foreach ($applicable as $row) {
                $profile = EmployeeProfile::query()
                    ->where('lms_staff_id', $row['lms_staff_id'])
                    ->firstOrFail();

                $update = [];
                /** @var array<string, array{from: mixed, to: mixed}> $changes */
                $changes = $row['changes'];
                foreach ($changes as $field => $diff) {
                    $update[$field] = $diff['to'];
                }

                $profile->forceFill($update)->save();

                $applied[] = [
                    'lms_staff_id' => $row['lms_staff_id'],
                    'profile_id' => $profile->id,
                    'changes' => $changes,
                ];
            }

            // Composite audit-log row capturing the whole import. The
            // per-profile audits the AuditObserver writes are still
            // emitted; this row is the umbrella for "this batch came
            // from a single Excel upload."
            AuditLog::query()->create([
                'auditable_type' => EmployeeProfile::class,
                'auditable_id' => null,
                'action' => 'employees.bulk_imported',
                'before' => null,
                'after' => [
                    'source_filename' => session('employee_bulk_import.source_filename'),
                    'profiles_touched' => count($applied),
                    'changeset' => $applied,
                ],
                'actor_id' => auth()->id(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        session()->forget('employee_bulk_import');

        return redirect()
            ->route('employees.index')
            ->with('success', sprintf(
                'Imported changes to %d profile%s.',
                count($applied),
                count($applied) === 1 ? '' : 's',
            ));
    }
}
