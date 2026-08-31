{{-- The printable face of an invoice or a bill.

     Shares its design with the payslip through
     `documents/partials/styles.blade.php` — same masthead, same identity rail,
     same filled band for the figure the document exists to state. What it
     deliberately does NOT borrow is the payslip's `+ − →` direction marks:
     money moves three ways on a payslip and one way on an invoice, and a
     structural device that encodes nothing is decoration.

     Laid out the way a BIR sales invoice is read rather than the way the
     screen shows it: the three sales buckets stack separately above the VAT,
     because those are the figures a return is filed from and collapsing them
     into one "subtotal" would lose the distinction between exempt and
     zero-rated for good.

     Everything registration-specific renders only when it exists. A school
     prints a clean document rather than empty labels or invented ones —
     those are facts about the client's registration, not defaults the
     software may assume. --}}
@php
    /** Centavos → peso string with thousands separators. */
    $peso = static fn (int $centavos): string => '₱'.number_format($centavos / 100, 2);

    /** Trailing zeros off a decimal(12,4) quantity: 2.5000 → 2.5, 1.0000 → 1. */
    $qty = static function (string $quantity): string {
        return str_contains($quantity, '.')
            ? rtrim(rtrim($quantity, '0'), '.')
            : $quantity;
    };

    $isSales = $invoice->isSales();
    $title = $isSales ? 'Sales invoice' : 'Purchase bill';
    $sellerName = $seller?->registered_name ?: $seller?->name;

    // Only the buckets carrying something, so an ordinary VATable sale is not
    // padded with two zero rows and a fully exempt invoice shows one line.
    $buckets = array_filter([
        'VATable sales' => $invoice->vatable_sales_centavos,
        'VAT-exempt sales' => $invoice->vat_exempt_sales_centavos,
        'Zero-rated sales' => $invoice->zero_rated_sales_centavos,
        'VAT' => $invoice->vat_centavos,
    ], static fn (int $centavos): bool => $centavos !== 0);

    $partlyPaid = $invoice->amount_paid_centavos !== 0;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} · {{ $invoice->number ?? 'Draft' }}</title>
    @include('documents.partials.styles')
    <style>
        /* Invoice-only, on top of the shared document styles. */

        /* Every column is given a width. Left to itself dompdf sizes them
           from content and runs the unit price into the VAT rate — "₱2,500.00"
           and "0%" print as "₱2,500.000%". */
        .items { margin-bottom: 10px; }
        .items th {
            font-size: 6.8pt;
            font-weight: normal;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #5B6675;
            text-align: left;
            border-bottom: 1px solid #C8CFD8;
            padding: 0 6px 4px 0;
        }
        .items td {
            font-size: 9pt;
            padding: 4px 6px 4px 0;
            vertical-align: top;
        }
        .items .banded td { background: #EEF1F5; }
        .items .c-desc { width: 46%; }
        .items .c-qty { width: 8%; text-align: right; }
        .items .c-price { width: 19%; text-align: right; }
        .items .c-vat { width: 9%; text-align: right; }
        .items .c-amount { width: 18%; text-align: right; padding-right: 4px; }
        .items td.c-qty,
        .items td.c-price,
        .items td.c-amount { white-space: nowrap; }
        .items .line-account {
            display: block;
            font-size: 7.5pt;
            color: #5B6675;
        }

        /* Bucket subtotals sit under the amount column they total. */
        .buckets { width: 55%; margin-left: 45%; margin-bottom: 12px; }
        .buckets td { font-size: 8.5pt; padding: 2.5px 0; }
        .buckets .label { color: #5B6675; }
        .buckets .value {
            text-align: right;
            padding-right: 4px;
            white-space: nowrap;
        }

        .settled { width: 55%; margin-left: 45%; margin-bottom: 12px; }
        .settled td { font-size: 8.5pt; padding: 2.5px 0; }
        .settled .label { color: #5B6675; }
        .settled .value { text-align: right; padding-right: 4px; white-space: nowrap; }
        .settled .balance td {
            border-top: 1px solid #C8CFD8;
            padding-top: 5px;
            font-weight: bold;
            color: #141A24;
        }

        /* A proforma must never be mistaken for an issued document, and a
           voided one must never be mistaken for a live claim. */
        .stamp {
            display: inline-block;
            border: 1.5px solid #1F3A5F;
            color: #1F3A5F;
            padding: 2px 8px;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        .aside { margin-top: 12px; }
    </style>
</head>
<body>

{{-- ── Masthead ────────────────────────────────────────────────── --}}
<table class="masthead">
    <tr>
        {{-- A data URI, not a URL: dompdf runs with enable_remote off and
             refuses http(s) images silently. --}}
        @if ($logo)
            <td class="seal-cell"><img class="seal" src="{{ $logo }}" alt=""></td>
        @endif
        <td>
            @if ($sellerName)
                <p class="org-name">{{ $sellerName }}</p>
            @endif
            @if ($seller?->tin)
                <p class="org-role">TIN <span class="mono">{{ $seller->tin }}</span></p>
            @endif
        </td>
        <td class="doctype">
            @if ($invoice->isVoided())
                <div class="stamp">Void</div><br>
            @elseif ($invoice->isDraft())
                <div class="stamp">Draft — not issued</div><br>
            @endif
            {{ $title }}
            <span class="sub mono">{{ $invoice->number ?? '—' }}</span>
        </td>
    </tr>
</table>

<div class="hairline"></div>

{{-- ── Counterparty rail, figures column ───────────────────────── --}}
<table class="body-grid">
    <tr>
        <td class="rail">
            <div class="rail-block">
                <p class="rail-label">{{ $isSales ? 'Bill to' : 'Received from' }}</p>
                <p class="doc-name">{{ $invoice->contact?->name ?? '—' }}</p>
                @if ($invoice->contact?->address)
                    <p class="rail-value rail-muted">{{ $invoice->contact->address }}</p>
                @endif
                @if ($invoice->contact?->tin)
                    <p class="rail-value rail-muted">TIN <span class="mono">{{ $invoice->contact->tin }}</span></p>
                @endif
            </div>

            {{-- Who the charges are for, when that is not the person paying.
                 A parent settling two children's fees cannot tell the
                 invoices apart without it. --}}
            @if ($invoice->student_name)
                <div class="rail-block">
                    <p class="rail-label">Charges for</p>
                    <p class="rail-value">{{ $invoice->student_name }}</p>
                </div>
            @endif

            <div class="rail-block">
                <p class="rail-label">Dates</p>
                <p class="rail-value">Issued {{ $invoice->issue_date->format('j F Y') }}</p>
                @if ($invoice->due_date)
                    <p class="rail-value">Due {{ $invoice->due_date->format('j F Y') }}</p>
                @endif
            </div>

            @if ($invoice->reference)
                <div class="rail-block">
                    <p class="rail-label">Reference</p>
                    <p class="rail-value mono">{{ $invoice->reference }}</p>
                </div>
            @endif

            @if ($seller?->business_address)
                <div class="rail-block">
                    <p class="rail-label">{{ $isSales ? 'Issued by' : 'Billed to' }}</p>
                    @if ($sellerName)
                        <p class="rail-value">{{ $sellerName }}</p>
                    @endif
                    <p class="rail-value rail-muted">{{ $seller->business_address }}</p>
                </div>
            @endif
        </td>

        <td class="money">
            <table class="items">
                <thead>
                <tr>
                    <th class="c-desc">Description</th>
                    <th class="c-qty">Qty</th>
                    <th class="c-price">Unit price</th>
                    <th class="c-vat">VAT</th>
                    <th class="c-amount">Amount</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($invoice->lines as $line)
                    <tr class="{{ $loop->index % 2 === 1 ? 'banded' : '' }}">
                        <td class="c-desc">
                            {{ $line->description }}
                            @if ($line->account)
                                <span class="line-account">{{ $line->account->name }}</span>
                            @endif
                        </td>
                        <td class="c-qty">{{ $qty((string) $line->quantity) }}</td>
                        <td class="c-price">{{ $peso($line->unit_price_centavos) }}</td>
                        <td class="c-vat">{{ $line->taxRate?->ratePercentLabel() ?? '—' }}</td>
                        {{-- The net, matching the bucket subtotals below. VAT
                             is shown once at the foot rather than smeared
                             across the lines, which is how the return reads
                             it. --}}
                        <td class="c-amount">{{ $peso($line->line_net_centavos) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if (! empty($buckets))
                <table class="buckets">
                    @foreach ($buckets as $label => $centavos)
                        <tr>
                            <td class="label">{{ $label }}</td>
                            <td class="value">{{ $peso($centavos) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            <table class="net">
                <tr>
                    <td class="net-label">
                        Total amount due
                        @if ($invoice->is_vat_inclusive && $invoice->vat_centavos !== 0)
                            <span class="net-sub">Prices shown are VAT-inclusive.</span>
                        @elseif ($invoice->due_date)
                            <span class="net-sub">Payable by {{ $invoice->due_date->format('j F Y') }}</span>
                        @endif
                    </td>
                    <td class="net-amount">{{ $peso($invoice->total_centavos) }}</td>
                </tr>
            </table>

            {{-- Only once something has been received. The balance is the
                 figure that decides whether anyone still has to act, so it
                 carries the weight rather than the amount already settled. --}}
            @if ($partlyPaid)
                <table class="settled">
                    <tr>
                        <td class="label">Paid</td>
                        <td class="value">{{ $peso($invoice->amount_paid_centavos) }}</td>
                    </tr>
                    <tr class="balance">
                        <td>Balance due</td>
                        <td class="value">{{ $peso($invoice->balanceDue()->centavos()) }}</td>
                    </tr>
                </table>
            @endif

            @if ($invoice->terms)
                <div class="note aside">
                    <p class="note-head">Terms</p>
                    <p class="note-body">{{ $invoice->terms }}</p>
                </div>
            @endif

            @if ($invoice->isVoided() && $invoice->void_reason)
                <div class="note aside">
                    <p class="note-head">Why this was voided</p>
                    <p class="note-body">{{ $invoice->void_reason }}</p>
                </div>
            @endif
        </td>
    </tr>
</table>

{{-- ── Colophon ────────────────────────────────────────────────── --}}
<table class="colophon">
    <tr>
        <td>
            @if ($invoice->isDraft())
                A draft. It reaches the books only when it is approved.
            @elseif ($invoice->isVoided())
                Voided. This document no longer claims payment.
            @else
                Issued {{ $invoice->issue_date->format('j F Y') }}.
            @endif
        </td>
        <td class="right mono">
            {{ $invoice->number ?? ('Draft '.$invoice->getKey()) }}
        </td>
    </tr>
</table>

</body>
</html>
