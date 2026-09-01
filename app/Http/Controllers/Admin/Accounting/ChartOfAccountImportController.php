<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\ApplyChartOfAccountImport;
use App\Exports\ChartOfAccountExport;
use App\Exports\ChartOfAccountTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\ChartOfAccountImportRequest;
use App\Imports\ChartOfAccountImport;
use App\Models\Pas\AuditLog;
use App\Models\Pas\ChartOfAccount;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The chart's spreadsheet round trip.
 *
 * Four endpoints and NO page of its own — unlike the contact importer, the
 * preview renders in a dialog on the chart index. The chart is one screen a
 * person reads as a whole, and sending them somewhere else to check a diff
 * against it is the wrong shape. `preview` therefore redirects back to the
 * index, which reopens the dialog because the parsed rows are in the session.
 *
 *   1. GET  chart-of-accounts/export            → the chart, importable
 *   2. GET  chart-of-accounts/import/template   → the same columns, empty
 *   3. POST chart-of-accounts/import/preview    → parse, stash under a token
 *   4. POST chart-of-accounts/import/confirm/{token} → apply
 */
final class ChartOfAccountImportController extends Controller
{
    /**
     * Flashed by an upload, read once by the chart index.
     *
     * Flat rather than nested under `chart_import` so `forget('chart_import')`
     * on a successful confirm cannot half-clear the flash bookkeeping.
     */
    public const SHOW_PREVIEW_KEY = 'chart_import_show';

    public function export(): BinaryFileResponse
    {
        Gate::authorize('viewAny', ChartOfAccount::class);

        return Excel::download(new ChartOfAccountExport, 'chart-of-accounts.xlsx');
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('create', ChartOfAccount::class);

        return Excel::download(
            new ChartOfAccountTemplateExport,
            'chart-of-accounts-template.xlsx',
        );
    }

    public function preview(ChartOfAccountImportRequest $request): RedirectResponse
    {
        $import = new ChartOfAccountImport;
        Excel::import($import, $request->file('file'));

        session([
            'chart_import.parsed' => $import->parsed(),
            'chart_import.token' => (string) Str::uuid(),
            'chart_import.source_filename' => $request->file('file')?->getClientOriginalName(),
        ]);

        $this->openTheDialogOnce();

        return redirect()->route('admin.chart-of-accounts.index');
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        Gate::authorize('create', ChartOfAccount::class);

        if (session('chart_import.token') !== $token) {
            return $this->refuse('token', 'Preview is no longer valid. Re-upload the worksheet.');
        }

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('chart_import.parsed');

        if ($parsed === null) {
            return $this->refuse('token', 'No parsed worksheet in session. Upload again.');
        }

        try {
            $result = app(ApplyChartOfAccountImport::class)->execute($parsed);
        } catch (DomainException $e) {
            return $this->refuse(
                'file',
                $e->getMessage().' Correct the worksheet and upload it again.',
            );
        }

        AuditLog::query()->create([
            'auditable_type' => ChartOfAccount::class,
            'auditable_id' => null,
            'action' => 'accounting.chart_of_accounts_imported',
            'before' => null,
            'after' => [
                'source_filename' => session('chart_import.source_filename'),
                ...$result,
            ],
            'actor_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        session()->forget('chart_import');

        return redirect()
            ->route('admin.chart-of-accounts.index')
            ->with('success', sprintf(
                '%d account%s created, %d updated.',
                $result['created'],
                $result['created'] === 1 ? '' : 's',
                $result['updated'],
            ));
    }

    private function refuse(string $key, string $message): RedirectResponse
    {
        // The one case a preview must survive a redirect: the file was
        // refused and the operator needs to see which rows to fix.
        $this->openTheDialogOnce();

        return redirect()
            ->route('admin.chart-of-accounts.index')
            ->withErrors([$key => $message]);
    }

    /**
     * Marks the next render of the chart as the one that shows the preview.
     *
     * The parsed rows themselves have to stay in the session until `confirm`
     * can read them — but if their PRESENCE were what opened the dialog, a
     * preview nobody confirmed would reopen it on every later visit to the
     * chart, which is a page people are on constantly. Flashed, so it survives
     * exactly one redirect and then goes.
     */
    private function openTheDialogOnce(): void
    {
        session()->flash(self::SHOW_PREVIEW_KEY, true);
    }
}
