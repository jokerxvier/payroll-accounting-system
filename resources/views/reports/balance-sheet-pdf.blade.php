{{-- Balance Sheet, rendered by dompdf (Phase 5 Slice 8b).

     Prints the same lines in the same order as the Inertia page and
     BalanceSheetExport: the statement arrives as a flat list already in print
     order, so this view decides how a line looks and nothing about which
     lines there are.

     One amount column per date — one, or two and their variance.

     Figures go through Centavos::formatted(), which never divides: a
     negative is in brackets, the way a contra account or a loss is read. --}}
@php
    /** @var \App\Services\Accounting\Reports\BalanceSheet $sheet */
    /** @var list<string> $notices */
    use App\Support\Centavos;

    $primary = $sheet->primary();
    $hasVariance = $sheet->hasVariance();
    $span = count($sheet->columns) + ($hasVariance ? 2 : 0) + 2;
    $symbol = $hasVariance ? '' : '₱';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Balance sheet · {{ $primary->asAt->toDateString() }}</title>
    @include('reports.partials.pdf-styles')
    @include('reports.partials.statement-pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Financial report</p>
    <h1>Balance sheet</h1>
    <div class="meta">
        <div>
            As at {{ $primary->asAt->format('j M Y') }}@if ($sheet->comparison() !== null), compared with {{ $sheet->comparison()->asAt->format('j M Y') }}@endif
        </div>
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
        <div>
            Posted journal entries only. Earnings are computed from income and expense accounts;
            the current year runs from {{ $primary->fiscalYearStart->format('j M Y') }}.
            @if ($hasVariance) Amounts in Philippine pesos. @endif
        </div>
    </div>

    @foreach ($notices ?? [] as $notice)
        <p class="notice">{{ $notice }}</p>
    @endforeach

    <table>
        <thead>
        <tr>
            <th style="width: 10%">Code</th>
            <th>Account</th>
            @foreach ($sheet->columns as $column)
                <th class="amount" style="width: {{ $hasVariance ? '17%' : '24%' }}">{{ $column->label() }}</th>
            @endforeach
            @if ($hasVariance)
                <th class="amount" style="width: 15%">Variance</th>
                <th class="amount" style="width: 9%">%</th>
            @endif
        </tr>
        </thead>
        <tbody>
        @foreach ($sheet->lines as $line)
            @if ($line->kind === 'heading')
                <tr class="heading"><td colspan="{{ $span }}">{{ $line->name }}</td></tr>
            @else
                <tr class="{{ $line->kind }}">
                    <td class="code">{{ $line->code }}</td>
                    <td class="name">{{ $line->name }}@if (! $line->isActive) (inactive)@endif</td>
                    @foreach ($line->amountsCentavos as $amount)
                        <td class="amount">{{ Centavos::formatted($amount, $symbol) }}</td>
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

    <p class="footnote">
        @foreach ($sheet->columns as $column)
            @if ($column->isBalanced())
                {{ $column->label() }}: assets equal liabilities plus equity.
            @else
                <strong>{{ $column->label() }}: the statement does not balance.</strong>
                Assets differ from liabilities plus equity by
                {{ Centavos::formatted(abs($column->varianceCentavos()), '₱') }}.
                Every posted entry balances on its own, so a discrepancy here means a line
                reached the ledger without going through posting.
            @endif
        @endforeach
    </p>
</div>
</body>
</html>
