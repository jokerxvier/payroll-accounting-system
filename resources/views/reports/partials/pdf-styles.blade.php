{{-- Shared inline stylesheet for the report PDFs. dompdf has limited
     support for external stylesheets, so every rule lives here and both
     report views @include it rather than each carrying its own copy.

     Two dompdf constraints are load-bearing, both learned from
     payslips/pdf.blade.php:

     1. DejaVu faces only. dompdf's core fonts (Helvetica/Times/Courier)
        use single-byte WinAnsi encoding and render ₱ (U+20B1) as "?".
        DejaVu ships with dompdf and carries the glyph.
     2. font-weight: bold, never 600. DejaVu ships normal + bold faces
        only; dompdf cannot map an intermediate weight onto the embedded
        bold TTF and silently falls back to a face without ₱. --}}
<style>
    * { box-sizing: border-box; }
    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9pt;
        color: #000;
        margin: 0;
        padding: 0;
    }
    .doc { padding: 24px; }
    .eyebrow {
        font-family: 'DejaVu Sans Mono', monospace;
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 2px;
        color: #555;
        margin: 0 0 6px 0;
    }
    h1 {
        font-family: 'DejaVu Serif', serif;
        font-size: 18pt;
        font-weight: normal;
        margin: 0 0 6px 0;
        letter-spacing: -0.5px;
    }
    .meta {
        font-size: 8.5pt;
        color: #555;
        margin: 0 0 14px 0;
    }
    .meta div { margin-bottom: 2px; }
    h2 {
        font-size: 8pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #555;
        margin: 16px 0 6px 0;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    thead th {
        font-size: 7.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #555;
        text-align: left;
        border-bottom: 1px solid #d1d1cc;
        padding: 4px 6px 4px 0;
    }
    tbody td {
        font-size: 8.5pt;
        padding: 4px 6px 4px 0;
        border-bottom: 1px solid #ecebe6;
        vertical-align: baseline;
    }
    th.amount, td.amount {
        text-align: right;
        padding-left: 12px;
        padding-right: 0;
    }
    td.amount, td.num { font-variant-numeric: tabular-nums; }
    .code {
        font-family: 'DejaVu Sans Mono', monospace;
        font-size: 8pt;
    }
    tfoot th {
        font-size: 8.5pt;
        font-weight: bold;
        border-top: 1.5px solid #000;
        border-bottom: 1.5px solid #000;
        padding: 6px 6px 6px 0;
        text-align: left;
    }
    tfoot th.amount { text-align: right; padding-left: 12px; padding-right: 0; }
    .empty {
        font-size: 9pt;
        color: #777;
        padding: 18px 0;
    }
    .footnote {
        margin-top: 18px;
        font-size: 7.5pt;
        color: #777;
        border-top: 1px solid #ecebe6;
        padding-top: 6px;
    }
</style>
