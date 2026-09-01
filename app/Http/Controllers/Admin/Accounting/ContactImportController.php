<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\ApplyContactImport;
use App\Exports\ContactExport;
use App\Exports\ContactTemplateExport;
use App\Http\Controllers\Admin\EmployeeBulkImportController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\ContactImportRequest;
use App\Imports\ContactImport;
use App\Models\Pas\AuditLog;
use App\Models\Pas\Contact;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Taking the contact register out to a spreadsheet, and putting it back.
 *
 * Five endpoints, and the export is deliberately one of them rather than
 * living on {@see ContactController}: export and import are one feature read
 * from two ends, and the file that comes out of `export` is the file `preview`
 * expects to go in.
 *
 *   1. GET  contacts/export             → the register, in importable shape
 *   2. GET  contacts/import/template    → the same columns, empty
 *   3. GET  contacts/import             → upload form + preview
 *   4. POST contacts/import/preview     → parse, stash under a token
 *   5. POST contacts/import/confirm/{token} → apply
 *
 * The preview-then-confirm split matches the opening-balance and open-item
 * importers rather than {@see EmployeeBulkImportController},
 * which applies clean rows and skips bad ones. A contact register is what
 * every invoice is addressed to, and part-applying a file leaves an operator
 * unable to say which half landed. Refusing the lot is cheap here because
 * matching on `code` makes a re-upload idempotent.
 *
 * Authorization goes through the policy, not an inline `hasAnyRole` — the same
 * choice `OpeningBalanceController` documents, because a role list repeated in
 * four methods sails straight past the platform-admin `Gate::before`.
 */
final class ContactImportController extends Controller
{
    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('viewAny', Contact::class);

        $search = trim((string) $request->query('search', ''));
        $role = (string) $request->query('role', '');

        // The list's own filters, carried through: what a person exports is
        // what they were looking at when they pressed the button.
        return Excel::download(
            new ContactExport(
                $search !== '' ? $search : null,
                in_array($role, ['customer', 'supplier'], true) ? $role : null,
            ),
            'contacts.xlsx',
        );
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('create', Contact::class);

        return Excel::download(new ContactTemplateExport, 'contacts-template.xlsx');
    }

    public function index(): Response
    {
        Gate::authorize('create', Contact::class);

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('contact_import.parsed');

        return Inertia::render('admin/accounting/contacts/import', [
            'parsed' => $parsed,
            'token' => session('contact_import.token'),
            'sourceFilename' => session('contact_import.source_filename'),
            'summary' => $parsed === null ? null : $this->summarise($parsed),
        ]);
    }

    public function preview(ContactImportRequest $request): RedirectResponse
    {
        $import = new ContactImport;
        Excel::import($import, $request->file('file'));

        session([
            'contact_import.parsed' => $import->parsed(),
            'contact_import.token' => (string) Str::uuid(),
            'contact_import.source_filename' => $request->file('file')?->getClientOriginalName(),
        ]);

        return redirect()->route('admin.contacts.import.index');
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        Gate::authorize('create', Contact::class);

        if (session('contact_import.token') !== $token) {
            return $this->refuse('token', 'Preview is no longer valid. Re-upload the worksheet.');
        }

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('contact_import.parsed');

        if ($parsed === null) {
            return $this->refuse('token', 'No parsed worksheet in session. Upload again.');
        }

        try {
            $result = app(ApplyContactImport::class)->execute($parsed);
        } catch (DomainException $e) {
            return $this->refuse('file', $e->getMessage().' Correct the worksheet and upload it again.');
        }

        AuditLog::query()->create([
            'auditable_type' => Contact::class,
            'auditable_id' => null,
            'action' => 'accounting.contacts_imported',
            'before' => null,
            'after' => [
                'source_filename' => session('contact_import.source_filename'),
                ...$result,
            ],
            'actor_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        session()->forget('contact_import');

        return redirect()
            ->route('admin.contacts.index')
            ->with('success', sprintf(
                '%d contact%s created, %d updated.',
                $result['created'],
                $result['created'] === 1 ? '' : 's',
                $result['updated'],
            ));
    }

    private function refuse(string $key, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.contacts.import.index')
            ->withErrors([$key => $message]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array{
     *     row_count: int,
     *     create_count: int,
     *     update_count: int,
     *     unchanged_count: int,
     *     error_count: int,
     * }
     */
    private function summarise(array $parsed): array
    {
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0];
        $errors = 0;

        foreach ($parsed as $row) {
            $action = (string) ($row['action'] ?? 'create');

            if (isset($counts[$action])) {
                $counts[$action]++;
            }

            $errors += count((array) ($row['errors'] ?? []));
        }

        return [
            'row_count' => count($parsed),
            'create_count' => $counts['create'],
            'update_count' => $counts['update'],
            'unchanged_count' => $counts['unchanged'],
            'error_count' => $errors,
        ];
    }
}
