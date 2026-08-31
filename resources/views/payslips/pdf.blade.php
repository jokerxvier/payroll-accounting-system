{{--
    Single-payslip PDF, rendered by dompdf.

    The audience is the member of staff, not the payroll officer. They keep
    this, photograph it for a loan or a visa application, and read it to
    answer one question: did my SSS actually get paid. Everything here serves
    that reading.

    Three constraints shape the markup:

      - dompdf has no flexbox and no grid. Every column is a table.
      - Only the DejaVu faces carry ₱ (U+20B1), and they ship with normal and
        bold ONLY. A `font-weight: 600` silently falls back to a face without
        the glyph and prints "?", so weights here are `normal` or `bold`.
      - These render 100 at a time inside a queued run, so no embedded
        display font: the personality comes from how the three DejaVu faces
        are set, not from a fourth family.
--}}
@php
    /** Pesos from integer centavos, with the sign. */
    $peso = static fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);

    // Shared with the on-screen payslip, so a line is not named one way on
    // the screen and another on the printout.
    $payslipLabel = \App\Support\PayslipLabel::humanise(...);

    // Which agency received what, and from whom. The arithmetic lives in
    // App\Support\ContributionLedger so it can be tested rather than
    // eyeballed on a rendered page.
    $agencies = \App\Support\ContributionLedger::build($deductions, $employer_lines);
    $creditedTotal = \App\Support\ContributionLedger::total($agencies);

    $period = $run['pay_period'] ?? null;

    /**
     * "1 – 30 July 2026" rather than two ISO strings. The reader is a member
     * of staff, not a system; the machine-readable form stays in the rail
     * where it can be quoted back to the payroll officer.
     */
    $periodLabel = static function (array $period): string {
        $start = \Illuminate\Support\Carbon::parse($period['start_date']);
        $end = \Illuminate\Support\Carbon::parse($period['end_date']);

        if ($start->isSameMonth($end)) {
            return $start->format('j').' – '.$end->format('j F Y');
        }

        return $start->format('j M Y').' – '.$end->format('j M Y');
    };
    $employeeName = $employee['full_name'] ?? ('Staff #'.$employee['lms_staff_id']);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip · {{ $employeeName }}</title>
    @include('documents.partials.styles')
    <style>
        /* Payslip-only, on top of the shared document styles. */

        /* Mark and heading share one line box, so the glyph sits on the
           heading's own baseline rather than being aligned to it by hand. */
        .flow {
            margin: 0 0 5px;
            font-size: 9pt;
            font-weight: bold;
            letter-spacing: 0.4px;
        }
        /* The direction marks are the one place this document raises its
           voice, so they are set to carry rather than to decorate. An invoice
           has a single flow and deliberately has no equivalent. */
        .flow-mark {
            font-size: 13pt;
            color: #1F3A5F;
            padding-right: 6px;
        }
        .flow-mark.credit { color: #0F5C4A; }
        .flow-note {
            font-size: 7.8pt;
            color: #5B6675;
            margin: 0 0 5px 17px;
        }

        .line-label { padding-left: 17px !important; }

        .credit-table td, .credit-table th {
            font-size: 8pt;
            padding: 3px 0;
            text-align: right;
        }
        .credit-table th {
            font-weight: normal;
            color: #5B6675;
            font-size: 6.8pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #C8CFD8;
        }
        .credit-table .agency { text-align: left; }
        .credit-table .sum {
            font-weight: bold;
            color: #0F5C4A;
            border-left: 1px solid #C8CFD8;
            padding-left: 10px !important;
        }
        .credit-table .credit-total td {
            border-top: 1px solid #C8CFD8;
            padding-top: 5px;
            font-weight: bold;
        }
    </style>
</head>
<body>

{{-- ── Masthead ────────────────────────────────────────────────── --}}
<table class="masthead">
    <tr>
        @if (!empty($school['logo']))
            <td class="seal-cell"><img class="seal" src="{{ $school['logo'] }}" alt=""></td>
        @endif
        <td>
            @if (!empty($school['name']))
                <p class="org-name">{{ $school['name'] }}</p>
            @endif
            <p class="org-role">Statement of earnings</p>
        </td>
        <td class="doctype">
            Payslip
            <span class="sub">
                @if ($period)
                    {{ $periodLabel($period) }}
                @else
                    Run {{ $run['id'] }}
                @endif
            </span>
        </td>
    </tr>
</table>

<div class="hairline"></div>

{{-- ── Identity rail, money column ─────────────────────────────── --}}
<table class="body-grid">
    <tr>
        <td class="rail">
            <div class="rail-block">
                <p class="rail-label">Paid to</p>
                <p class="doc-name">{{ $employeeName }}</p>
                @if (!empty($employee['staff_no']))
                    <p class="rail-value rail-muted">Staff no. <span class="mono">{{ $employee['staff_no'] }}</span></p>
                @endif
                @if (!empty($employee['email']))
                    <p class="rail-value rail-muted">{{ $employee['email'] }}</p>
                @endif
            </div>

            @if ($period)
                <div class="rail-block">
                    <p class="rail-label">Period covered</p>
                    <p class="rail-value">{{ $periodLabel($period) }}</p>
                    <p class="rail-value rail-muted">Quote <span class="mono">{{ $period['code'] }}</span> to payroll.</p>
                </div>
            @endif

            @if ($employee['tin'] || $employee['sss_number'] || $employee['philhealth_number'] || $employee['pagibig_number'])
                <div class="rail-block">
                    <p class="rail-label">Your numbers</p>
                    <table class="id-table">
                        @foreach ([
                            'TIN' => $employee['tin'],
                            'SSS' => $employee['sss_number'],
                            'PhilHealth' => $employee['philhealth_number'],
                            'Pag-IBIG' => $employee['pagibig_number'],
                        ] as $name => $value)
                            @if ($value)
                                <tr>
                                    <td class="id-name">{{ $name }}</td>
                                    <td class="mono">{{ $value }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </table>
                </div>
            @endif

            {{-- Only when it adds something: the masthead already names the
                 school, so repeating the name alone is noise. --}}
            @if (!empty($school['tin']) || !empty($school['address']))
                <div class="rail-block">
                    <p class="rail-label">Paid by</p>
                    @if (!empty($school['name']))
                        <p class="rail-value">{{ $school['name'] }}</p>
                    @endif
                    @if (!empty($school['address']))
                        <p class="rail-value rail-muted">{{ $school['address'] }}</p>
                    @endif
                    @if (!empty($school['tin']))
                        <p class="rail-value rail-muted">TIN <span class="mono">{{ $school['tin'] }}</span></p>
                    @endif
                </div>
            @endif

            @if (!empty($payslip['applied_exemptions']))
                <div class="rail-block">
                    <p class="rail-label">Exemptions applied</p>
                    @foreach ($payslip['applied_exemptions'] as $code)
                        <p class="rail-value mono">{{ $code }}</p>
                    @endforeach
                </div>
            @endif
        </td>

        <td class="money">
            @include('payslips.partials.flow', [
                'mark' => '+',
                'title' => 'What you earned',
                'lines' => $earnings,
                'totalLabel' => 'Gross pay',
                'totalCentavos' => $payslip['gross_pay_centavos'],
            ])

            @include('payslips.partials.flow', [
                'mark' => '−',
                'title' => 'What was withheld from your pay',
                'lines' => $deductions,
                'totalLabel' => 'Total withheld',
                'totalCentavos' => $payslip['total_employee_deductions_centavos'],
            ])

            <table class="net">
                <tr>
                    <td class="net-label">
                        Net pay
                        <span class="net-sub">Taxable income {{ $peso($payslip['taxable_income_centavos']) }}</span>
                    </td>
                    <td class="net-amount">{{ $peso($payslip['net_pay_centavos']) }}</td>
                </tr>
            </table>

            @if (!empty($employer_lines))
                @include('payslips.partials.flow', [
                    'mark' => '→',
                    'accent' => 'credit',
                    'title' => 'Paid for you, on top of your pay',
                    'note' => 'The school pays these in your name. They are not taken from your pay and do not change the figure above.',
                    'lines' => $employer_lines,
                    'totalLabel' => 'Total paid by the school',
                    'totalCentavos' => $payslip['total_employer_contributions_centavos'],
                ])
            @endif

            @if (!empty($agencies))
                <div class="note">
                    <p class="note-head">Credited to your record this period</p>
                    <p class="note-body" style="margin-bottom: 7px">Your share and the school's share reach each agency together. This is the figure they hold against your name.</p>
                    <table class="credit-table">
                        <tr>
                            <th class="agency">Agency</th>
                            <th>Your share</th>
                            <th>School's share</th>
                            <th class="sum">Credited</th>
                        </tr>
                        @foreach ($agencies as $agency)
                            <tr>
                                <td class="agency">{{ $agency['label'] }}</td>
                                <td>{{ $peso($agency['yours']) }}</td>
                                <td>{{ $peso($agency['school']) }}</td>
                                <td class="sum">{{ $peso($agency['credited']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="credit-total">
                            <td class="agency">Total</td>
                            <td></td>
                            <td></td>
                            <td class="sum">{{ $peso($creditedTotal) }}</td>
                        </tr>
                    </table>
                </div>
            @endif
        </td>
    </tr>
</table>

{{-- ── Colophon ────────────────────────────────────────────────── --}}
<table class="colophon">
    <tr>
        <td>
            Computed {{ $payslip['computed_at_formatted'] ?? '—' }}.
            Keep this for your records — it is not a demand for payment.
        </td>
        <td class="right mono">
            Run {{ $run['id'] }} · Payslip {{ $payslip['id'] }}
        </td>
    </tr>
</table>

</body>
</html>
