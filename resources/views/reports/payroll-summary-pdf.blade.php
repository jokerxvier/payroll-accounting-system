{{-- Payroll summary report, rendered by dompdf (Phase 4 W13 export parity).
     Mirrors the columns of the Inertia page and of
     PayrollSummaryReportExport so the three formats agree row for row.
     Currency is formatted in PHP — dompdf runs no JS. --}}
@php
    /** Centavos → peso string with thousands separators. */
    $peso = static fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll summary · {{ $from->toDateString() }} to {{ $to->toDateString() }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Report</p>
    <h1>Payroll summary</h1>
    <div class="meta">
        <div>Period: {{ $from->toFormattedDateString() }} &ndash; {{ $to->toFormattedDateString() }}</div>
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
        <div>Voided runs are excluded.</div>
    </div>

    @if (count($rows) === 0)
        <p class="empty">No payroll runs fell inside this date range.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Run</th>
                <th>Status</th>
                <th>Period</th>
                <th>Start</th>
                <th>End</th>
                <th class="amount">Employees</th>
                <th class="amount">Gross pay</th>
                <th class="amount">Employee deductions</th>
                <th class="amount">Employer contributions</th>
                <th class="amount">Net pay</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="code">{{ $row['run_id'] }}</td>
                    <td>{{ str_replace('_', ' ', $row['status']) }}</td>
                    <td class="code">{{ $row['pay_period_code'] ?? '—' }}</td>
                    <td>{{ $row['pay_period_start'] ?? '—' }}</td>
                    <td>{{ $row['pay_period_end'] ?? '—' }}</td>
                    <td class="amount num">{{ number_format($row['employee_count']) }}</td>
                    <td class="amount">{{ $peso($row['gross_pay_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['total_employee_deductions_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['total_employer_contributions_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['total_net_pay_centavos']) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <th colspan="5">Total</th>
                <th class="amount">{{ number_format($totals['employee_count']) }}</th>
                <th class="amount">{{ $peso($totals['gross_pay_centavos']) }}</th>
                <th class="amount">{{ $peso($totals['total_employee_deductions_centavos']) }}</th>
                <th class="amount">{{ $peso($totals['total_employer_contributions_centavos']) }}</th>
                <th class="amount">{{ $peso($totals['total_net_pay_centavos']) }}</th>
            </tr>
            </tfoot>
        </table>
    @endif

    <p class="footnote">
        {{ config('app.name') }} · Payroll summary · {{ count($rows) }} {{ Str::plural('run', count($rows)) }}
    </p>
</div>
</body>
</html>
