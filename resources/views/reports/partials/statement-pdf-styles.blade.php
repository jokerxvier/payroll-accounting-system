{{-- Rules the two financial statements add to pdf-styles: the line kinds a
     statement prints, and a dense layout for an Income Statement with a
     column a month.

     Same dompdf constraints as pdf-styles — DejaVu only, and bold rather
     than a numeric weight. --}}
<style>
    tr.heading td {
        font-size: 8pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #555;
        padding-top: 12px;
        border-bottom: 1px solid #d1d1cc;
    }
    tr.account td.name, tr.computed td.name { padding-left: 10px; }
    tr.computed td.name { font-style: italic; }
    tr.detail td { color: #555; font-size: 8pt; border-bottom: none; }
    tr.detail td.name { padding-left: 24px; }
    tr.subtotal td { font-weight: bold; border-top: 1px solid #000; }
    tr.total td {
        font-weight: bold;
        border-top: 1.5px solid #000;
        border-bottom: 1.5px solid #000;
        padding-top: 6px;
        padding-bottom: 6px;
    }
    .notice { font-size: 8pt; color: #555; margin: 0 0 4px 0; }

    /* More than six amount columns: fixed layout so the figures stay in
       their columns whatever the account names do, and small enough that
       twelve months and a total fit across an A4 landscape page. The size
       is set by the widest figure a column must hold — a bracketed
       seven-figure total in bold — not by what reads comfortably. */
    table.dense { table-layout: fixed; }
    table.dense thead th { font-size: 5.5pt; letter-spacing: 0; padding-right: 0; }
    table.dense tbody td { font-size: 5.5pt; padding: 3px 0; }
    table.dense th.amount, table.dense td.amount { padding-left: 4px; }
    table.dense td.name { font-size: 6pt; }
    table.dense .code { font-size: 5.5pt; }
    table.dense tr.heading td { font-size: 6pt; }
</style>
