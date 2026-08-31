<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Contacts\ImportLmsGuardians;
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
 * Brings the school's parents into the contact register.
 *
 * Same shape as {@see OpeningBalanceController} minus the template and the
 * upload, because the source is the LMS rather than a spreadsheet: preview
 * under a session token, then confirm. The preview writes nothing, which is
 * what makes it safe to show someone a list of a hundred families and let them
 * read it before anything lands in the books.
 *
 * Authorization goes through `ContactPolicy` rather than the inline
 * `hasAnyRole` the employee importer uses — the accounting module gates on
 * policies, and importing counterparties is contact management.
 */
final class GuardianImportController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('create', Contact::class);

        return Inertia::render('admin/accounting/contacts/import-guardians', [
            'parsed' => session('guardian_import.parsed'),
            'token' => session('guardian_import.token'),
            'summary' => $this->summarise(session('guardian_import.parsed')),
        ]);
    }

    public function preview(ImportLmsGuardians $importer): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        $rows = $importer->preview();
        $token = (string) Str::uuid();

        session([
            'guardian_import.parsed' => $rows,
            'guardian_import.token' => $token,
        ]);

        return redirect()->route('admin.contacts.import-guardians.index');
    }

    public function confirm(Request $request, string $token, ImportLmsGuardians $importer): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        if (session('guardian_import.token') !== $token) {
            return redirect()
                ->route('admin.contacts.import-guardians.index')
                ->withErrors(['token' => 'That preview is no longer valid. Run it again.']);
        }

        /** @var list<array<string, mixed>>|null $rows */
        $rows = session('guardian_import.parsed');

        if ($rows === null) {
            return redirect()
                ->route('admin.contacts.import-guardians.index')
                ->withErrors(['token' => 'No preview in session. Run it again.']);
        }

        $result = $importer->apply($rows);

        // One composite row for the whole import, on top of the per-contact
        // audits the observer writes — the umbrella saying "this batch came
        // from the school records at this moment".
        AuditLog::query()->create([
            'auditable_type' => Contact::class,
            'auditable_id' => null,
            'action' => 'contacts.guardians_imported',
            'before' => null,
            'after' => $result + ['rows_previewed' => count($rows)],
            'actor_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        session()->forget('guardian_import');

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', sprintf(
                'Imported %d new contact%s and linked %d student%s.',
                $result['contacts_created'],
                $result['contacts_created'] === 1 ? '' : 's',
                $result['students_linked'],
                $result['students_linked'] === 1 ? '' : 's',
            ));
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array<string, int>|null
     */
    private function summarise(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        $counts = ['create' => 0, 'link' => 0, 'unchanged' => 0, 'errors' => 0, 'students' => 0];

        foreach ($rows as $row) {
            if (! empty($row['errors'])) {
                $counts['errors']++;

                continue;
            }

            $counts[(string) $row['action']]++;
            $counts['students'] += count((array) ($row['students'] ?? []));
        }

        return $counts;
    }
}
