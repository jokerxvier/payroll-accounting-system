{{-- Journal Report, rendered by dompdf (Phase 5 Slice 8a).

     Grouped by entry rather than flattened one row per line, because on paper
     the thing being read is the transaction. The xlsx export takes the
     opposite shape on purpose — a spreadsheet is sorted and pivoted, and
     grouped rows break both. --}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Pas\JournalEntry> $entries */
    $peso = static fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);
    $cell = static fn (int $centavos): string => $centavos === 0 ? '' : '₱'.number_format($centavos / 100, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Journal report · {{ $from->toDateString() }} to {{ $to->toDateString() }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Financial report</p>
    <h1>Journal report</h1>
    <div class="meta">
        <div>{{ $from->toFormattedDateString() }} &ndash; {{ $to->toFormattedDateString() }}</div>
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
        <div>{{ $entries->count() }} posted {{ Str::plural('entry', $entries->count()) }}.</div>
    </div>

    @if ($entries->isEmpty())
        <p class="empty">No entry was posted with a date inside this range.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Entry</th>
                <th>Account</th>
                <th>Description</th>
                <th class="amount">Debit</th>
                <th class="amount">Credit</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($entries as $entry)
                @foreach ($entry->lines as $index => $line)
                    <tr>
                        {{-- The entry's own columns print once, against its first line:
                             on paper the repetition is noise, and the grouping is what
                             makes a transaction legible. --}}
                        <td>{{ $index === 0 ? $entry->date->toDateString() : '' }}</td>
                        <td class="code">{{ $index === 0 ? $entry->entry_number : '' }}</td>
                        <td>{{ $line->account?->code }} {{ $line->account?->name }}</td>
                        <td>{{ $line->description ?? ($index === 0 ? $entry->narration : '') }}</td>
                        <td class="amount">{{ $cell($line->debit_centavos) }}</td>
                        <td class="amount">{{ $cell($line->credit_centavos) }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <th colspan="4">Total</th>
                <th class="amount">{{ $peso((int) $entries->sum('total_debit_centavos')) }}</th>
                <th class="amount">{{ $peso((int) $entries->sum('total_credit_centavos')) }}</th>
            </tr>
            </tfoot>
        </table>
    @endif
</div>
</body>
</html>
