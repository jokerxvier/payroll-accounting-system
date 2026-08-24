{{-- The printable face of an invoice or bill — Phase 5 Slice 5.

     Laid out the way a BIR sales invoice is read rather than the way the
     screen shows it: the three sales buckets are stacked separately above
     the VAT, because those are the figures a return is filed from and
     collapsing them into one "subtotal" would lose the distinction between
     exempt and zero-rated for good.

     Everything registration-specific renders only when it exists. A school
     that has not supplied its TIN, or a series with no Authority To Print,
     prints a clean document rather than empty labels or invented ones —
     those are facts about the client's registration, not defaults the
     software may assume.

     Currency is formatted in PHP; dompdf runs no JS. --}}
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
    $title = $isSales ? 'Sales Invoice' : 'Purchase Bill';
    $sellerName = $seller?->registered_name ?: $seller?->name;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} · {{ $invoice->number ?? 'Draft' }}</title>
    @include('reports.partials.pdf-styles')
    <style>
        /* Invoice-specific additions. The shared partial carries the two
           dompdf constraints these must respect: DejaVu faces only, and
           font-weight: bold rather than 600. */
        .masthead { width: 100%; margin-bottom: 20px; }
        .masthead td { vertical-align: top; border: none; padding: 0; }
        .masthead .issuer { width: 60%; }
        .masthead .docref { width: 40%; text-align: right; }
        .issuer-name {
            font-family: 'DejaVu Serif', serif;
            font-size: 13pt;
            margin: 0 0 4px 0;
        }
        .issuer-detail { font-size: 8pt; color: #555; margin: 0 0 1px 0; }
        .doc-title {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 9pt;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: #555;
            margin: 0 0 4px 0;
        }
        .doc-number {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 14pt;
            font-weight: bold;
            margin: 0 0 6px 0;
        }
        .doc-dates { font-size: 8pt; color: #555; }
        .doc-dates div { margin-bottom: 1px; }
        .stamp {
            display: inline-block;
            border: 1.5px solid #000;
            padding: 2px 8px;
            font-size: 9pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 6px;
        }
        .party {
            border-top: 1px solid #d1d1cc;
            border-bottom: 1px solid #d1d1cc;
            padding: 8px 0;
            margin-bottom: 14px;
        }
        .party-label {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #777;
            margin: 0 0 3px 0;
        }
        .party-name { font-size: 10pt; font-weight: bold; margin: 0 0 2px 0; }
        .party-detail { font-size: 8pt; color: #555; margin: 0 0 1px 0; }
        .totals { width: 58%; margin-left: 42%; margin-top: 10px; }
        .totals td {
            border: none;
            padding: 3px 0;
            font-size: 8.5pt;
        }
        .totals td.label { color: #555; }
        .totals td.value { text-align: right; font-variant-numeric: tabular-nums; }
        .totals tr.grand td {
            border-top: 1.5px solid #000;
            border-bottom: 1.5px solid #000;
            font-size: 10.5pt;
            font-weight: bold;
            padding: 6px 0;
        }
        .notes { margin-top: 20px; font-size: 8pt; color: #555; }
        .notes .heading {
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #777;
            margin-bottom: 3px;
        }
        .permit {
            margin-top: 22px;
            border-top: 1px solid #ecebe6;
            padding-top: 6px;
            font-size: 7pt;
            color: #777;
        }
    </style>
</head>
<body>
<div class="doc">

    <table class="masthead">
        <tr>
            <td class="issuer">
                @if ($sellerName)
                    <p class="issuer-name">{{ $sellerName }}</p>
                @endif
                @if ($seller?->business_address)
                    <p class="issuer-detail">{{ $seller->business_address }}</p>
                @endif
                @if ($seller?->tin)
                    <p class="issuer-detail">TIN: {{ $seller->tin }}</p>
                @endif
            </td>
            <td class="docref">
                @if ($invoice->isVoided())
                    <div class="stamp">Void</div>
                @elseif ($invoice->isDraft())
                    {{-- Marked plainly so a proforma can never be mistaken
                         for an issued document. --}}
                    <div class="stamp">Draft &mdash; not issued</div>
                @endif
                <p class="doc-title">{{ $title }}</p>
                <p class="doc-number">{{ $invoice->number ?? '—' }}</p>
                <div class="doc-dates">
                    <div>Issued: {{ $invoice->issue_date->toFormattedDateString() }}</div>
                    @if ($invoice->due_date)
                        <div>Due: {{ $invoice->due_date->toFormattedDateString() }}</div>
                    @endif
                    @if ($invoice->reference)
                        <div>Ref: {{ $invoice->reference }}</div>
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="party">
        <p class="party-label">{{ $isSales ? 'Bill to' : 'Received from' }}</p>
        <p class="party-name">{{ $invoice->contact?->name ?? '—' }}</p>
        @if ($invoice->contact?->address)
            <p class="party-detail">{{ $invoice->contact->address }}</p>
        @endif
        @if ($invoice->contact?->tin)
            <p class="party-detail">TIN: {{ $invoice->contact->tin }}</p>
        @endif
    </div>

    <table>
        <thead>
        <tr>
            <th style="width: 44%;">Description</th>
            <th class="amount">Qty</th>
            <th class="amount">Unit price</th>
            <th>VAT</th>
            <th class="amount">Amount</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($invoice->lines as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="amount num">{{ $qty((string) $line->quantity) }}</td>
                <td class="amount">{{ $peso($line->unit_price_centavos) }}</td>
                <td class="code">{{ $line->taxRate?->ratePercentLabel() ?? '—' }}</td>
                {{-- The net, matching the bucket subtotals below. VAT is
                     shown once at the foot rather than smeared across the
                     lines, which is how the return reads it. --}}
                <td class="amount">{{ $peso($line->line_net_centavos) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        {{-- Each bucket appears only when it carries something, so an
             ordinary VATable sale is not padded with two zero rows. A fully
             exempt invoice shows only its exempt line. --}}
        @if ($invoice->vatable_sales_centavos !== 0)
            <tr>
                <td class="label">VATable sales</td>
                <td class="value">{{ $peso($invoice->vatable_sales_centavos) }}</td>
            </tr>
        @endif
        @if ($invoice->vat_exempt_sales_centavos !== 0)
            <tr>
                <td class="label">VAT-exempt sales</td>
                <td class="value">{{ $peso($invoice->vat_exempt_sales_centavos) }}</td>
            </tr>
        @endif
        @if ($invoice->zero_rated_sales_centavos !== 0)
            <tr>
                <td class="label">Zero-rated sales</td>
                <td class="value">{{ $peso($invoice->zero_rated_sales_centavos) }}</td>
            </tr>
        @endif
        @if ($invoice->vat_centavos !== 0)
            <tr>
                <td class="label">VAT</td>
                <td class="value">{{ $peso($invoice->vat_centavos) }}</td>
            </tr>
        @endif
        <tr class="grand">
            <td class="label">Total amount due</td>
            <td class="value">{{ $peso($invoice->total_centavos) }}</td>
        </tr>
        @if ($invoice->amount_paid_centavos !== 0)
            <tr>
                <td class="label">Paid</td>
                <td class="value">{{ $peso($invoice->amount_paid_centavos) }}</td>
            </tr>
            <tr>
                <td class="label">Balance due</td>
                <td class="value">{{ $peso($invoice->balanceDue()->centavos()) }}</td>
            </tr>
        @endif
    </table>

    @if ($invoice->is_vat_inclusive && $invoice->vat_centavos !== 0)
        <p class="notes">Prices shown are VAT-inclusive.</p>
    @endif

    @if ($invoice->terms)
        <div class="notes">
            <div class="heading">Terms</div>
            {{ $invoice->terms }}
        </div>
    @endif

    @if ($invoice->isVoided() && $invoice->void_reason)
        <div class="notes">
            <div class="heading">Void reason</div>
            {{ $invoice->void_reason }}
        </div>
    @endif

    @if ($series)
        {{-- Only reached when the series carries ATP details. A school that
             has not registered this document type prints nothing here rather
             than a permit line with blanks in it. --}}
        <div class="permit">
            Authority To Print No. {{ $series->atp_number }}
            @if ($series->permit_issued_at)
                &middot; dated {{ $series->permit_issued_at->toFormattedDateString() }}
            @endif
            @if ($series->serial_start !== null && $series->serial_end !== null)
                &middot; Serial range {{ $series->format($series->serial_start) }}
                to {{ $series->format($series->serial_end) }}
            @endif
        </div>
    @endif

</div>
</body>
</html>
