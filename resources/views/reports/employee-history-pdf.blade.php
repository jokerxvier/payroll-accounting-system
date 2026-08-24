{{-- Employee history report, rendered by dompdf (Phase 4 W13 export parity).
     Two tables: the per-payslip timeline with running totals, then the
     year-to-date summary. Matches the Inertia page and
     EmployeeHistoryReportExport so the three formats agree. --}}
@php
    $peso = static fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee history · {{ $employee['full_name'] ?? 'Employee' }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>
<div class="doc">
    <p class="eyebrow">Report</p>
    <h1>Employee history</h1>
    <div class="meta">
        <div>
            <strong>{{ $employee['full_name'] ?? 'Unknown employee' }}</strong>
            @if (! empty($employee['staff_no']))
                &middot; <span class="code">{{ $employee['staff_no'] }}</span>
            @endif
        </div>
        @if (! empty($employee['email']))
            <div>{{ $employee['email'] }}</div>
        @endif
        <div>Generated: {{ $generatedAt->toDayDateTimeString() }}</div>
        <div>Payslips from voided runs are excluded.</div>
    </div>

    <h2>Payslip timeline</h2>

    @if (count($rows) === 0)
        <p class="empty">This employee has no payslips from non-voided runs.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Period</th>
                <th>Start</th>
                <th>End</th>
                <th class="amount">Gross pay</th>
                <th class="amount">Deductions</th>
                <th class="amount">Net pay</th>
                <th class="amount">Cumulative gross</th>
                <th class="amount">Cumulative deductions</th>
                <th class="amount">Cumulative net</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="code">{{ $row['pay_period_code'] ?? '—' }}</td>
                    <td>{{ $row['pay_period_start'] ?? '—' }}</td>
                    <td>{{ $row['pay_period_end'] ?? '—' }}</td>
                    <td class="amount">{{ $peso($row['gross_pay_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['total_employee_deductions_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['net_pay_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['cumulative_gross_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['cumulative_deductions_centavos']) }}</td>
                    <td class="amount">{{ $peso($row['cumulative_net_centavos']) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <th colspan="3">Total · {{ $totals['payslip_count'] }} {{ Str::plural('payslip', $totals['payslip_count']) }}</th>
                <th class="amount">{{ $peso($totals['gross_pay_centavos']) }}</th>
                <th class="amount">{{ $peso($totals['total_employee_deductions_centavos']) }}</th>
                <th class="amount">{{ $peso($totals['total_net_pay_centavos']) }}</th>
                <th class="amount" colspan="3"></th>
            </tr>
            </tfoot>
        </table>
    @endif

    @if (count($ytdByYear) > 0)
        <h2>Year to date</h2>
        <table>
            <thead>
            <tr>
                <th>Year</th>
                <th class="amount">Payslips</th>
                <th class="amount">Gross pay</th>
                <th class="amount">Employee deductions</th>
                <th class="amount">Employer contributions</th>
                <th class="amount">Net pay</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($ytdByYear as $year)
                <tr>
                    <td class="code">{{ $year['year'] }}</td>
                    <td class="amount num">{{ number_format($year['payslip_count']) }}</td>
                    <td class="amount">{{ $peso($year['gross_pay_centavos']) }}</td>
                    <td class="amount">{{ $peso($year['total_employee_deductions_centavos']) }}</td>
                    <td class="amount">{{ $peso($year['total_employer_contributions_centavos']) }}</td>
                    <td class="amount">{{ $peso($year['total_net_pay_centavos']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <p class="footnote">
        {{ config('app.name') }} · Employee history · {{ $employee['full_name'] ?? 'Unknown employee' }}
    </p>
</div>
</body>
</html>
