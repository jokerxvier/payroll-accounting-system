{{-- The house style for documents a person outside the office keeps: the
     payslip a member of staff files for a loan application, the invoice a
     parent is handed at the cashier.

     Reports are a different animal and keep their own
     `reports/partials/pdf-styles.blade.php` — those are worksheets read at a
     desk by someone reconciling figures, and they want density where these
     want a document that can be read once and trusted.

     Three dompdf constraints are load-bearing here:

       1. **DejaVu faces only.** The core fonts (Helvetica/Times/Courier) use
          single-byte WinAnsi encoding and render ₱ (U+20B1) as "?".
       2. **`font-weight: bold`, never 600.** DejaVu ships normal and bold
          only; dompdf cannot map an intermediate weight and silently falls
          back to a face without ₱.
       3. **No flexbox, no grid.** Every column here is a table.

     The palette is the cool navy and grey of a school seal and a bank
     statement, chosen partly because it survives a mono laser printer: navy
     goes to dark grey and the white type on the total band stays legible. --}}
<style>
    @page { margin: 14mm 13mm; }

    * { box-sizing: border-box; }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9.5pt;
        line-height: 1.35;
        color: #141A24;
        margin: 0;
        padding: 0;
    }

    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; padding: 0; }

    /* Mono marks a value someone may have to read out or type into a form:
       an ID, a document number, a reference, a period code. */
    .mono {
        font-family: 'DejaVu Sans Mono', monospace;
        letter-spacing: 0.3px;
    }

    /* ── Masthead ──────────────────────────────────────────────────── */
    .masthead { margin-bottom: 9px; }
    .masthead td { vertical-align: middle; }
    .seal-cell { width: 62px; }
    .seal { height: 52px; width: auto; }
    .org-name {
        font-size: 12pt;
        font-weight: bold;
        letter-spacing: 0.2px;
        margin: 0;
    }
    .org-role {
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 1.6px;
        color: #5B6675;
        margin: 2px 0 0;
    }
    .doctype {
        /* Given a width, so a long registered name wraps in its own cell
           rather than crowding the document type against it. */
        width: 25%;
        text-align: right;
        font-size: 8pt;
        text-transform: uppercase;
        letter-spacing: 2.4px;
        color: #1F3A5F;
        font-weight: bold;
    }
    .doctype .sub {
        display: block;
        margin-top: 3px;
        font-size: 8pt;
        letter-spacing: 0.6px;
        color: #5B6675;
        font-weight: normal;
        text-transform: none;
    }

    .hairline { border-top: 1.5px solid #1F3A5F; margin-bottom: 12px; }

    /* ── Identity rail beside the figures ──────────────────────────── */
    .body-grid td { vertical-align: top; }
    .rail { width: 31%; padding-right: 16px; border-right: 1px solid #C8CFD8; }
    .money { width: 69%; padding-left: 18px; }

    .rail-block { margin-bottom: 13px; }
    .rail-label {
        font-size: 6.8pt;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: #5B6675;
        margin: 0 0 3px;
    }
    /* Serif, and used once per document: the name of whoever this concerns. */
    .doc-name {
        font-family: 'DejaVu Serif', serif;
        font-size: 15pt;
        line-height: 1.15;
        margin: 0 0 4px;
    }
    .rail-value { font-size: 8.5pt; margin: 0; }
    .rail-value .mono { font-size: 8pt; }
    .rail-muted { color: #5B6675; }

    .id-table td { padding: 2px 0; font-size: 8pt; }
    .id-table .id-name { color: #5B6675; width: 38%; }

    /* ── Lines of money ────────────────────────────────────────────── */
    .lines { margin-bottom: 14px; }
    .lines td { padding: 3.5px 0; font-size: 9pt; }
    .lines .banded td { background: #EEF1F5; }
    .line-amount {
        text-align: right;
        padding-right: 4px !important;
        white-space: nowrap;
    }
    .lines .line-total td {
        border-top: 1px solid #C8CFD8;
        padding-top: 5px;
        font-weight: bold;
        background: transparent;
    }

    /* ── The one filled block on the page: what the document is for ── */
    .net { background: #1F3A5F; margin: 0 0 14px; page-break-inside: avoid; }
    .net td { padding: 9px 12px; vertical-align: middle; }
    .net-label {
        color: #C8CFD8;
        font-size: 7.5pt;
        text-transform: uppercase;
        letter-spacing: 2px;
    }
    .net-sub {
        display: block;
        margin-top: 2px;
        font-size: 7.5pt;
        letter-spacing: 0;
        text-transform: none;
        color: #9FB0C4;
    }
    .net-amount {
        font-family: 'DejaVu Serif', serif;
        font-size: 19pt;
        color: #FFFFFF;
        text-align: right;
        white-space: nowrap;
    }

    /* ── Asides and footer ─────────────────────────────────────────── */
    .note {
        border: 1px solid #C8CFD8;
        padding: 9px 11px;
        page-break-inside: avoid;
    }
    .note-head { font-size: 8.5pt; font-weight: bold; margin: 0 0 1px; }
    .note-body { font-size: 7.8pt; color: #5B6675; margin: 0; }

    /* Fixed, so the document closes at the foot of the sheet instead of
       trailing off wherever its last line happened to end. dompdf positions a
       fixed block against the page box and repeats it if the document runs to
       two pages, which is the correct behaviour for a page footer. */
    .colophon {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        padding-top: 7px;
        border-top: 1px solid #C8CFD8;
    }
    .colophon td { font-size: 7.2pt; color: #5B6675; }
    .colophon .right { text-align: right; }
</style>
