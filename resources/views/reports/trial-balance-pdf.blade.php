{{-- Trial Balance, rendered by dompdf (Phase 5 Slice 8a).

     Mirrors the columns of the Inertia page and of TrialBalanceExport so the
     three formats agree row for row.

     A zero balance prints as an empty cell rather than as 0.00 in both
     columns — a trial balance showing a zero on each side of the same account
     reads as two offsetting facts instead of one absent one. --}}
@php
    /** @var \App\Services\Accounting\Reports\TrialBalance $trialBalance */
    $peso = static fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);
    $cell = static fn (int $centavos): string => $centavos === 0 ? '' : '₱'.number_format($centavos / 100, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trial balance · {{ $trialBalance->to->toDateString() }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Financial report</p>
    <h1>Trial balance</h1>
    <div class="meta">
        <div>
            @if ($trialBalance->from === null)
                Since inception to {{ $trialBalance->to->toFormattedDateString() }}
            @else
                {{ $trialBalance->from->toFormattedDateString() }} &ndash; {{ $trialBalance->to->toFormattedDateString() }}
            @endif
        </div>
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
        <div>Posted journal entries only. Reversals and their originals both appear and offset.</div>
    </div>

    @if (count($trialBalance->rows) === 0)
        <p class="empty">No account carried a balance or moved inside this range.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Code</th>
                <th>Account</th>
                <th>Type</th>
                <th class="amount">Opening Dr</th>
                <th class="amount">Opening Cr</th>
                <th class="amount">Period Dr</th>
                <th class="amount">Period Cr</th>
                <th class="amount">Closing Dr</th>
                <th class="amount">Closing Cr</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($trialBalance->rows as $row)
                <tr>
                    <td class="code">{{ $row->code }}</td>
                    <td>{{ $row->name }}</td>
                    <td>{{ ucfirst($row->type) }}</td>
                    <td class="amount">{{ $cell($row->openingBalanceDebitCentavos()) }}</td>
                    <td class="amount">{{ $cell($row->openingBalanceCreditCentavos()) }}</td>
                    <td class="amount">{{ $cell($row->periodDebitCentavos) }}</td>
                    <td class="amount">{{ $cell($row->periodCreditCentavos) }}</td>
                    <td class="amount">{{ $cell($row->closingBalanceDebitCentavos()) }}</td>
                    <td class="amount">{{ $cell($row->closingBalanceCreditCentavos()) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <th colspan="3">Total</th>
                <th class="amount">{{ $peso($trialBalance->totalOpeningDebitCentavos()) }}</th>
                <th class="amount">{{ $peso($trialBalance->totalOpeningCreditCentavos()) }}</th>
                <th class="amount">{{ $peso($trialBalance->totalPeriodDebitCentavos()) }}</th>
                <th class="amount">{{ $peso($trialBalance->totalPeriodCreditCentavos()) }}</th>
                <th class="amount">{{ $peso($trialBalance->totalClosingDebitCentavos()) }}</th>
                <th class="amount">{{ $peso($trialBalance->totalClosingCreditCentavos()) }}</th>
            </tr>
            </tfoot>
        </table>

        <p class="footnote">
            @if ($trialBalance->isBalanced())
                Debits equal credits in all three column pairs. The ledger balances.
            @else
                <strong>The ledger does not balance.</strong>
                @if ($trialBalance->closingVarianceCentavos() > 0)
                    Closing debits exceed closing credits by {{ $peso($trialBalance->closingVarianceCentavos()) }}.
                @elseif ($trialBalance->closingVarianceCentavos() < 0)
                    Closing credits exceed closing debits by {{ $peso(-$trialBalance->closingVarianceCentavos()) }}.
                @else
                    The closing columns agree but the opening or period columns do not.
                @endif
                Every posted entry balances on its own, so a discrepancy here means a line
                reached the ledger without going through posting.
            @endif
        </p>
    @endif
</div>
</body>
</html>
