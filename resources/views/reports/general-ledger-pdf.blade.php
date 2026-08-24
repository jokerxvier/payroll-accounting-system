{{-- General Ledger for one account, rendered by dompdf (Phase 5 Slice 8a).

     Opens with the balance brought forward and closes with the balance
     carried forward, so the page reconciles without the reader fetching the
     prior period to find where the running balance started. --}}
@php
    /** @var \App\Services\Accounting\Reports\AccountLedger $ledger */
    $peso = static fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);
    $cell = static fn (int $centavos): string => $centavos === 0 ? '' : '₱'.number_format($centavos / 100, 2);
    $account = $ledger->account;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>General ledger · {{ $account->code }} {{ $account->name }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Financial report</p>
    <h1>{{ $account->code }} &middot; {{ $account->name }}</h1>
    <div class="meta">
        <div>
            @if ($ledger->from === null)
                Since inception to {{ $ledger->to->toFormattedDateString() }}
            @else
                {{ $ledger->from->toFormattedDateString() }} &ndash; {{ $ledger->to->toFormattedDateString() }}
            @endif
        </div>
        <div>{{ ucfirst($account->type) }} &middot; {{ ucfirst($account->normal_balance) }}-normal</div>
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
    </div>

    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Entry</th>
            <th>Reference</th>
            <th>Description</th>
            <th>Contra accounts</th>
            <th class="amount">Debit</th>
            <th class="amount">Credit</th>
            <th class="amount">Balance</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td>{{ $ledger->from?->toDateString() }}</td>
            <td colspan="4"><em>Balance brought forward</em></td>
            <td class="amount"></td>
            <td class="amount"></td>
            <td class="amount">{{ $peso($ledger->openingRawCentavos) }}</td>
        </tr>
        @forelse ($ledger->lines as $line)
            <tr>
                <td>{{ $line->date->toDateString() }}</td>
                <td class="code">{{ $line->entryNumber }}@if ($line->isReversal) &middot; rev @endif</td>
                <td>{{ $line->reference }}</td>
                <td>{{ $line->description ?? $line->narration }}</td>
                <td>{{ implode('; ', $line->contraAccounts) }}</td>
                <td class="amount">{{ $cell($line->debitCentavos) }}</td>
                <td class="amount">{{ $cell($line->creditCentavos) }}</td>
                <td class="amount">{{ $peso($line->runningRawCentavos) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="empty">No posted movement on this account inside the range.</td>
            </tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <th colspan="5">Balance carried forward</th>
            <th class="amount">{{ $peso($ledger->totalDebitCentavos()) }}</th>
            <th class="amount">{{ $peso($ledger->totalCreditCentavos()) }}</th>
            <th class="amount">{{ $peso($ledger->closingRawCentavos()) }}</th>
        </tr>
        </tfoot>
    </table>

    <p class="footnote">
        Balances are stated as debits less credits, so the running column adds up line by line.
        In this account's own direction the closing balance is
        {{ $peso(abs($ledger->closingNaturalCentavos())) }}
        {{ $ledger->closingNaturalCentavos() < 0 ? 'contra' : $account->normal_balance }}.
    </p>
</div>
</body>
</html>
