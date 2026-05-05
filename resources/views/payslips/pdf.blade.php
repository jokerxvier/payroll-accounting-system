{{-- Single-payslip PDF rendered by dompdf. Mirrors the Inertia
     payslip detail page (THEME.md §6.4) using inline CSS — dompdf has
     limited support for external stylesheets so we keep everything
     self-contained. Currency formatting is done in PHP via number_format
     since dompdf doesn't run JS. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip · {{ $employee['full_name'] ?? ('Staff '.$employee['lms_staff_id']) }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 11pt;
            color: #000;
            margin: 0;
            padding: 0;
        }
        .doc { padding: 28px; }
        .eyebrow {
            font-family: 'Courier', monospace;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #555;
            margin: 0 0 8px 0;
        }
        h1 {
            font-family: 'Times New Roman', serif;
            font-size: 22pt;
            font-weight: normal;
            margin: 0 0 8px 0;
            letter-spacing: -0.5px;
        }
        .meta {
            font-size: 10pt;
            color: #555;
            margin: 0 0 16px 0;
        }
        .meta div { margin-bottom: 2px; }
        .section {
            border-top: 1px solid #d1d1cc;
            padding: 10px 0;
        }
        h2 {
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #555;
            margin: 0 0 6px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td, th {
            font-size: 10pt;
            padding: 3px 0;
            vertical-align: baseline;
        }
        td.amount, th.amount {
            text-align: right;
            font-variant-numeric: tabular-nums;
            padding-left: 16px;
        }
        td .code, .gov-id {
            font-family: 'Courier', monospace;
            font-size: 8pt;
            color: #777;
        }
        tfoot th {
            border-top: 1px solid #d1d1cc;
            padding: 6px 0;
            font-weight: 600;
        }
        .net {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            padding: 10px 0;
            margin-top: 4px;
        }
        .net-row {
            width: 100%;
        }
        .net-row td {
            padding: 0;
        }
        .net-label {
            font-size: 11pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .net-amount {
            font-family: 'Times New Roman', serif;
            font-size: 18pt;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .gov-grid {
            width: 100%;
            margin-top: 4px;
        }
        .gov-grid td {
            width: 25%;
            padding-right: 8px;
            vertical-align: top;
        }
        .gov-label {
            font-size: 9pt;
            color: #555;
        }
        .gov-id {
            display: block;
            margin-top: 1px;
        }
        .footnote {
            font-size: 9pt;
            color: #555;
            margin-top: 6px;
        }
        .ref-footer {
            margin-top: 22px;
            padding-top: 8px;
            border-top: 1px solid #d1d1cc;
            text-align: center;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #777;
        }
    </style>
</head>
<body>
<div class="doc">
    <p class="eyebrow">
        Payslip · {{ $run['pay_period']['code'] ?? ('Run '.$run['id']) }}
        @if(!empty($run['pay_period']))
            · {{ $run['pay_period']['start_date'] }} → {{ $run['pay_period']['end_date'] }}
        @endif
    </p>
    <h1>{{ $employee['full_name'] ?? ('Staff #'.$employee['lms_staff_id']) }}</h1>

    <div class="meta">
        @if(!empty($employee['staff_no']))
            <div><strong>Staff no.</strong> <span style="font-family:'Courier',monospace">{{ $employee['staff_no'] }}</span></div>
        @endif
        @if(!empty($employee['email']))
            <div><strong>Email</strong> {{ $employee['email'] }}</div>
        @endif
        <div><strong>Computed</strong> {{ $payslip['computed_at_formatted'] ?? '—' }}</div>
        <div><strong>Run status</strong> {{ ucwords(str_replace('_', ' ', $run['status'])) }}</div>
    </div>

    {{-- Government IDs --}}
    @if($employee['tin'] || $employee['sss_number'] || $employee['philhealth_number'] || $employee['pagibig_number'])
        <div class="section">
            <h2>Government IDs</h2>
            <table class="gov-grid">
                <tr>
                    @if($employee['tin'])
                        <td><span class="gov-label">TIN</span><span class="gov-id">{{ $employee['tin'] }}</span></td>
                    @endif
                    @if($employee['sss_number'])
                        <td><span class="gov-label">SSS</span><span class="gov-id">{{ $employee['sss_number'] }}</span></td>
                    @endif
                    @if($employee['philhealth_number'])
                        <td><span class="gov-label">PhilHealth</span><span class="gov-id">{{ $employee['philhealth_number'] }}</span></td>
                    @endif
                    @if($employee['pagibig_number'])
                        <td><span class="gov-label">Pag-IBIG</span><span class="gov-id">{{ $employee['pagibig_number'] }}</span></td>
                    @endif
                </tr>
            </table>
        </div>
    @endif

    {{-- Earnings --}}
    @include('payslips.partials.section', [
        'title' => 'Earnings',
        'lines' => $earnings,
        'totalLabel' => 'Gross pay',
        'totalCentavos' => $payslip['gross_pay_centavos'],
    ])

    {{-- Employee deductions --}}
    @include('payslips.partials.section', [
        'title' => 'Employee deductions',
        'lines' => $deductions,
        'totalLabel' => 'Total deductions',
        'totalCentavos' => $payslip['total_employee_deductions_centavos'],
    ])

    {{-- Net pay highlight --}}
    <div class="net">
        <table class="net-row">
            <tr>
                <td class="net-label">Net pay</td>
                <td class="net-amount">₱{{ number_format($payslip['net_pay_centavos'] / 100, 2) }}</td>
            </tr>
        </table>
        <div class="footnote">
            Taxable income: ₱{{ number_format($payslip['taxable_income_centavos'] / 100, 2) }}
        </div>
    </div>

    {{-- Employer contributions (informational) --}}
    @if(!empty($employer_lines))
        @include('payslips.partials.section', [
            'title' => 'Employer contributions',
            'lines' => $employer_lines,
            'totalLabel' => 'Total employer contributions',
            'totalCentavos' => $payslip['total_employer_contributions_centavos'],
        ])
        <p class="footnote">Paid by the employer; does not affect employee net pay.</p>
    @endif

    {{-- Exemptions footer --}}
    @if(!empty($payslip['applied_exemptions']))
        <p class="footnote">
            <strong>Statutory exemptions applied:</strong>
            @foreach($payslip['applied_exemptions'] as $code)
                <span style="font-family:'Courier',monospace; margin-right:6px">{{ $code }}</span>
            @endforeach
        </p>
    @endif

    <div class="ref-footer">
        Run #{{ $run['id'] }} · Payslip #{{ $payslip['id'] }} · Computed {{ $payslip['computed_at_formatted'] ?? '—' }}
    </div>
</div>
</body>
</html>
