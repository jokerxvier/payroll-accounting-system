{{-- Income Statement, rendered by dompdf (Phase 5 Slice 8b).

     Prints the same lines in the same order as the Inertia page and
     IncomeStatementExport. The number of amount columns follows the
     comparison: one, two and their variance, or a column a month.

     Figures go through Centavos::formatted(), which never divides; a
     negative is in brackets, as on the Balance Sheet. --}}
@php
    /** @var \App\Services\Accounting\Reports\IncomeStatement $statement */
    use App\Support\Centavos;

    $columns = $statement->columns->columns;
    $hasVariance = $statement->columns->hasVariance;
    $primary = $statement->columns->range;
    $amountColumns = count($columns) + ($hasVariance ? 2 : 0);
    $dense = $amountColumns > 6;
    $span = $amountColumns + 2;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Income statement · {{ $primary->to->toDateString() }}</title>
    @include('reports.partials.pdf-styles')
    @include('reports.partials.statement-pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Financial report</p>
    <h1>Income statement</h1>
    <div class="meta">
        <div>{{ $primary->from->format('j M Y') }} &ndash; {{ $primary->to->format('j M Y') }}</div>
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
        <div>Posted journal entries only. Amounts in Philippine pesos.</div>
    </div>

    @foreach ($statement->columns->notices as $notice)
        <p class="notice">{{ $notice }}</p>
    @endforeach

    <table @class(['dense' => $dense])>
        <thead>
        <tr>
            <th style="width: {{ $dense ? '5%' : '9%' }}">Code</th>
            <th style="width: {{ $dense ? '14%' : '31%' }}">Account</th>
            @foreach ($columns as $column)
                <th class="amount">{{ $column->label }}</th>
            @endforeach
            @if ($hasVariance)
                <th class="amount">Variance</th>
                <th class="amount">%</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @foreach ($statement->lines as $line)
            @if ($line->kind === 'heading')
                <tr class="heading"><td colspan="{{ $span }}">{{ $line->name }}</td></tr>
            @else
                <tr class="{{ $line->kind }}">
                    <td class="code">{{ $line->code }}</td>
                    <td class="name">{{ $line->name }}@if (! $line->isActive) (inactive)@endif</td>
                    @foreach ($line->amountsCentavos as $amount)
                        <td class="amount">{{ $amount === 0 && $line->kind === 'account' ? '' : Centavos::formatted($amount) }}</td>
                    @endforeach
                    @if ($hasVariance)
                        <td class="amount">{{ $line->varianceCentavos === null ? '' : Centavos::formatted($line->varianceCentavos) }}</td>
                        <td class="amount">{{ Centavos::percentFromBasisPoints($line->varianceBasisPoints) }}</td>
                    @endif
                </tr>
            @endif
        @endforeach
        </tbody>
    </table>

    @if (! $statement->hasAccountLines())
        <p class="empty">No income or expense account moved inside this range.</p>
    @endif
</div>
</body>
</html>
