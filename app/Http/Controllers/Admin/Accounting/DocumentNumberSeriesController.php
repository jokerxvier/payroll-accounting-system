<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\DocumentNumberSeriesRequest;
use App\Models\Pas\DocumentNumberSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for document numbering series — Phase 5 Slice 5.
 *
 * This is where the client's Authority To Print details go: the permit
 * number, its date, and the serial range the BIR authorised. Those are facts
 * about a specific school's registration, not something the software can
 * infer, so the screen exists from the start and the fields stay optional
 * until the client supplies them. A series with no ATP still issues numbers
 * — an unregistered school is a normal state, not an error — and the printed
 * document simply omits the permit footer rather than showing empty labels.
 *
 * Sheet-based, mirroring the contact register: a series is a handful of
 * fields on one record, which is exactly the case `RULES.md` §807 prefers a
 * sheet for.
 *
 * There is no delete. A series that has issued numbers is the record of
 * which serials went out, and removing it would orphan every document drawn
 * from it. Deactivating is the reversible equivalent and the allocator
 * refuses an inactive series.
 */
final class DocumentNumberSeriesController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', DocumentNumberSeries::class);

        $series = DocumentNumberSeries::query()
            ->orderBy('document_type')
            ->get()
            ->map(fn (DocumentNumberSeries $row): array => [
                'id' => $row->id,
                'document_type' => $row->document_type,
                'label' => $row->label,
                'prefix' => $row->prefix,
                'padding' => $row->padding,
                'next_number' => $row->next_number,
                // What the next document would actually be stamped with,
                // so the operator can see the format rather than infer it
                // from prefix and padding.
                'next_formatted' => $row->format($row->next_number),
                'serial_start' => $row->serial_start,
                'serial_end' => $row->serial_end,
                'atp_number' => $row->atp_number,
                'permit_issued_at' => $row->permit_issued_at?->toDateString(),
                'has_authority_to_print' => $row->hasAuthorityToPrint(),
                'remaining_in_range' => $row->remainingInRange(),
                'is_active' => $row->is_active,
                'can' => [
                    'update' => Gate::allows('update', $row),
                ],
            ])
            ->values();

        return Inertia::render('admin/accounting/document-series/index', [
            'series' => $series,
            'documentTypes' => DocumentNumberSeries::TYPES,
            'can' => [
                'create' => Gate::allows('create', DocumentNumberSeries::class),
            ],
        ]);
    }

    public function store(DocumentNumberSeriesRequest $request): RedirectResponse
    {
        Gate::authorize('create', DocumentNumberSeries::class);

        DocumentNumberSeries::create($request->validated());

        return redirect()
            ->route('admin.document-series.index')
            ->with('success', 'Numbering series created.');
    }

    public function update(
        DocumentNumberSeriesRequest $request,
        DocumentNumberSeries $documentSeries,
    ): RedirectResponse {
        Gate::authorize('update', $documentSeries);

        $documentSeries->update($request->validated());

        return redirect()
            ->route('admin.document-series.index')
            ->with('success', 'Numbering series updated.');
    }
}
