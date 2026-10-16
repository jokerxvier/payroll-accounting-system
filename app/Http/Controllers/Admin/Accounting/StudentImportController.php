<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Contacts\ImportLmsStudents;
use App\Http\Controllers\Controller;
use App\Models\Pas\AuditLog;
use App\Models\Pas\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Walks the school's student roster and brings each student's payer into the
 * contact register.
 *
 * Same shape as {@see OpeningBalanceController} minus the template and the
 * upload, because the source is the LMS rather than a spreadsheet: preview
 * under a session token, then confirm. The preview writes nothing, which is
 * what makes it safe to show someone a list of a few hundred students and let
 * them read it before anything lands in the books.
 *
 * Authorization goes through `ContactPolicy` rather than the inline
 * `hasAnyRole` the employee importer uses — the accounting module gates on
 * policies, and importing counterparties is contact management.
 */
final class StudentImportController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('create', Contact::class);

        return Inertia::render('admin/accounting/contacts/import-students', [
            'parsed' => session('student_import.parsed'),
            'token' => session('student_import.token'),
            'summary' => $this->summarise(session('student_import.parsed')),
        ]);
    }

    public function preview(ImportLmsStudents $importer): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        $rows = $importer->preview();
        $token = (string) Str::uuid();

        session([
            'student_import.parsed' => $rows,
            'student_import.token' => $token,
        ]);

        return redirect()->route('admin.contacts.import-students.index');
    }

    public function confirm(Request $request, string $token, ImportLmsStudents $importer): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        if (session('student_import.token') !== $token) {
            return redirect()
                ->route('admin.contacts.import-students.index')
                ->withErrors(['token' => 'That preview is no longer valid. Run it again.']);
        }

        /** @var list<array<string, mixed>>|null $rows */
        $rows = session('student_import.parsed');

        if ($rows === null) {
            return redirect()
                ->route('admin.contacts.import-students.index')
                ->withErrors(['token' => 'No preview in session. Run it again.']);
        }

        $result = $importer->apply($rows);

        // One composite row for the whole import, on top of the per-contact
        // audits the observer writes — the umbrella saying "this batch came
        // from the school records at this moment".
        AuditLog::query()->create([
            'auditable_type' => Contact::class,
            'auditable_id' => null,
            'action' => 'contacts.students_imported',
            'before' => null,
            'after' => $result + ['rows_previewed' => count($rows)],
            'actor_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        session()->forget('student_import');

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', sprintf(
                'Linked %d student%s and created %d new contact%s.',
                $result['students_linked'],
                $result['students_linked'] === 1 ? '' : 's',
                $result['contacts_created'],
                $result['contacts_created'] === 1 ? '' : 's',
            ));
    }

    /**
     * `students` is how many rows would be acted on; `payers` is how many
     * distinct new contacts those rows would create — siblings share one.
     *
     * @param  list<array<string, mixed>>|null  $rows
     * @return array{students: int, payers: int, unchanged: int, errors: int}|null
     */
    private function summarise(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        $counts = ['students' => 0, 'payers' => 0, 'unchanged' => 0, 'errors' => 0];

        /** @var array<int, true> $newPayers */
        $newPayers = [];

        foreach ($rows as $row) {
            if (! empty($row['errors'])) {
                $counts['errors']++;

                continue;
            }

            $action = (string) $row['action'];

            if ($action === ImportLmsStudents::ACTION_UNCHANGED) {
                $counts['unchanged']++;

                continue;
            }

            $counts['students']++;

            if ($action === ImportLmsStudents::ACTION_CREATE && isset($row['lms_parent_id'])) {
                $newPayers[(int) $row['lms_parent_id']] = true;
            }
        }

        $counts['payers'] = count($newPayers);

        return $counts;
    }
}
