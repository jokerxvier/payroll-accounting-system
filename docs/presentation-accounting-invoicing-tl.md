# Paano i-present ang Accounting at Invoicing

Script para sa live demo sa client o school owner. Hindi ito user manual — script ito ng
kung paano mo ipapakita.

**Taglish ito, hindi malalim na Tagalog.** Hindi tina-translate ang "invoice", "chart of
accounts", "journal entry", "receivable" — yun naman talaga yung nasa screen. Kung sasabihin
mong "tuntungang-aklat ng mga akawnt", hahanapin pa nila kung saan yun sa menu. Tagalog yung
kwento, English yung pinipindot.

---

## Kung isang minuto lang meron ka

> "Dalawang bagay yung hawak ng system na 'to nang sabay: yung **sinisingil** sa mga parents,
> at yung **books** ng school. Pag in-approve mo yung invoice, automatic na pumapasok yun sa
> ledger — walang separate encoding, walang double entry na ginagawa ng tao. Tapos pag may
> nagbayad, automatic bumababa yung receivable."

Yun na yun. Lahat ng iba, detalye na lang kung paano yun nangyayari nang hindi nasisira.

---

## Bago ka mag-demo: checklist

**Gamitin mo yung school 1 (`Payroll and Accounting`). Wag yung MindHearts.**
Walang accounting period at walang kahit isang invoice yung MindHearts, kaya sa first
Approve mo pa lang, mag-e-error na agad — closed period. Nakaka-awkward yun sa harap ng
client at wala kang mabilis na kabawian.

Bago magsimula, i-check:

| | Dapat |
|---|---|
| Napiling school | `Payroll and Accounting` (school 1) |
| Accounting periods | July, August, September 2026 — **open** lahat |
| Naka-login as | `accountant` o `super-admin` (kailangan ng POST_LEDGER para maka-approve) |
| `npm run dev` | Running, o naka-`npm run build` na |
| Storage link | Naka-`php artisan storage:link` na, kung hindi walang lalabas na logo sa PDF |

Buksan mo na yung mga tab na 'to bago magsimula, para hindi ka mag-antay ng loading sa
harap nila: `/admin/invoices`, `/admin/reports/accounting-dashboard`,
`/admin/reports/invoice-dashboard`.

---

## Yung flow: anim na parte

Sundin mo yung order na 'to. Ganito rin naka-ayos yung sidebar (Transactions → Books →
Reports → Settings), at may reason yun: kailangan muna nilang maintindihan yung **structure**
bago mo ipakita yung **galaw**, tapos yung **reports** na yung magbubuklod ng dalawa.

---

### 1. Chart of Accounts — yung structure ng books

**Punta:** Sidebar → Books → Chart of accounts

**Sabihin mo:**

> "Ito yung structure ng books ng school. 40 accounts, hati sa limang types — Assets,
> Liabilities, Equity, Revenue, Expenses. Balance sheet muna, tapos income statement —
> same lang sa pagbabasa ng totoong books."

**Ipakita mo:**
- Yung tabs per type — i-click mo yung **Revenue** para makita yung Tuition Fee Income.
- Isang naka-lock na account (may padlock). Sabihin mo: *"Yung mga 'to, ginagamit mismo ng
  system. Hindi pwedeng i-delete, hindi rin pwedeng palitan yung type — kasi may mga naka-post
  nang entries na naka-turo dito."*
- I-click yung **Import** — modal yung lalabas, nasa loob na yung Export at yung template.

**Punchline:**

> "Hindi mo kailangang i-encode isa-isa yung 40. I-export mo, ayusin mo sa Excel, i-upload mo
> ulit. Tapos bago pa yun ma-apply, ipapakita muna sa'yo kung **anong column yung magbabago** —
> hindi lang 'twelve accounts will be updated', kundi 'yung Utilities Expense, from operating
> to investing'."

---

### 2. Contacts — sino yung sinisingil, sino yung binabayaran

**Punta:** Sidebar → Transactions → Contacts

**Sabihin mo:**

> "Isang listahan lang 'to para sa dalawang bagay: yung mga parents na sinisingil, at yung mga
> supplier na binabayaran. Pwedeng pareho — may mga store na bumibili rin sa school."

**Ipakita mo:**
- **Import guardians** — kunin yung mga parents galing sa school records. Sabihin mo: *"Isang
  family, isang contact — kahit tatlo yung anak. Kasi pag na-doble mo yung parent, mahahati
  yung receivable nila, tapos wala nang makakasingil nang tama."*
- **Export** at **Import** — yung round trip. *"I-labas mo, ayusin mo yung isang daang spelling
  sa Excel, ibalik mo."*

**Punchline:**

> "Pag binalik mo yung file na hindi mo ginalaw, sasabihin niya sa'yo na **walang nagbago**.
> Hindi siya basta mag-o-overwrite. Kasi yung tanong na sinasagot ng preview ay: sa isang
> daang row, alin ba talaga dun yung labindalawang gumalaw."

---

### 3. Invoice — from draft to paid

**Dito ka mag-focus. Ito yung core ng demo.**

**Punta:** Sidebar → Transactions → Invoices → **New invoice**

**Ipakita mo, step by step:**

1. **Pumili ng customer.** May search box — hindi mo kailangang mag-scroll sa 800 families.
   Pag parent yung napili mo, **may lalabas na student select** — kasi magkaiba yung
   nagbabayad at yung pinapaaral.
2. **Yung mga lines.** Piliin yung account (Tuition Fee Income), amount, VAT kung meron.
3. **Repeat** — i-on mo yung toggle. *"Pag monthly 'to, isang beses mo lang gagawin. Every
   7PM, chine-check ng system kung may dapat i-generate."*
4. **I-save.** Draft muna. Ituro mo yung badge.

> "Draft pa lang 'to. Wala pang epekto sa books. Pwede pang i-edit, pwede pang i-delete."

5. **Approve.** Tapos ituro mo yung badge na nag-change, sabihin mo:

> "Sa isang click na yun, dalawang bagay yung nangyari. Naging official document na yung
> invoice, tapos **pumasok na siya sa ledger** — Debit Accounts Receivable, Credit Tuition
> Fee Income, Credit Output VAT. Walang nag-encode nun. Tapos kung mag-fail yung books,
> hindi rin matutuloy yung approval — kasi hindi pwedeng may document na na-send na sa
> parent pero wala sa books."

6. **Print / PDF.** Buksan mo yung PDF. Ituro mo yung **QR code** at yung **Pay now** button.

> "Pag nasa screen sila, iki-click nila yung Pay now. Pag naka-print, isi-scan nila yung QR.
> Iisa lang naman yung pupuntahan."

7. **Send.** Ipakita mo yung dialog — naka-autofill na yung email galing sa contact, pero
   pwede mo pang palitan.

> "May logo ng school yung email, tapos naka-attach yung PDF."

**Punchline:**

> "Isang beses lang yung encoding. Yung invoice, yung ledger, yung email, tapos yung PDF —
> iisang document lang yan. Hindi apat na trabaho."

---

### 4. Payments — paano bumababa yung receivable

**Punta:** Sidebar → Transactions → Payments → **New payment** (`type=receipt`)

**Ipakita mo:**
- **Received from** — may search din.
- Pag napili mo yung contact, **lalabas yung mga unpaid invoices niya**.
- I-allocate mo yung bayad sa invoice.
- I-post.

**Sabihin mo:**

> "Debit Cash, Credit Accounts Receivable. Bumaba yung utang nila, tumaas yung cash — sabay,
> sa iisang galaw lang."

Balikan mo yung invoice, ituro mo yung status na naging **Partially paid** o **Paid**.

**Punchline:**

> "Hindi 'to separate na record na kailangan mong i-reconcile every end of month. Yung
> payment mismo, yun na yung ledger entry."

---

### 5. Opening Balances at Opening Items — "paano kung mid-year kami lumipat?"

**Ito yung tanong na siguradong itatanong. Ihanda mo.**

**Punta:** Sidebar → Settings → Opening balances, tapos Opening items

**Sabihin mo:**

> "Walang school na nag-start sa zero. Kung September kayo lumipat, may dala kayong balance.
> May dalawang parte yung sagot."

**Una — Opening balances.** *"Ito yung totals. Magkano yung cash, magkano yung receivable,
magkano yung payable, as of yung araw na binuksan yung books. Isang worksheet, i-upload,
tapos bago pa mag-post, ipapakita muna kung **balanced ba** — at kung hindi, kung magkano
yung kulang."*

**Pangalawa — Opening items.** *"Ito naman yung detalye. Kasi hindi sapat na alam mong
₱1,425,325.37 yung receivable — kailangan mong malaman kung **kaninong invoice** yun, para
may masingil ka."*

Ituro mo yung **reconciliation panel**:

> "Tignan niyo. Sabi ng ledger, ₱1,425,325.37 yung receivable. Yung walong documents na
> na-upload, ₱1,425,325.37 din. **Zero yung difference.** Kung hindi tumugma, sasabihin niya
> kung magkano — pero hindi ka niya pipigilan, kasi ibig sabihin nun, hindi tugma yung dating
> system niyo sa sarili niyang records, at kailangan niyong malaman yun."

**Punchline:**

> "Pagkatapos nito, yung mga lumang invoice na yun, **pwede nang bayaran online**. Same pay
> link, same PDF. Walang pinagkaiba sa bagong invoice."

---

### 6. Reports at Dashboard — dito nagsasama lahat

**Punta:** Sidebar → Reports

Ganito yung order:

**a. Accounting dashboard** (`/admin/reports/accounting-dashboard`)

> "Ito yung tanong ng owner: kumikita ba tayo. Cash, Receivables, Payables, Income, Expenses,
> Net income. Galing lahat sa mga **naka-post na** journal entries — hindi sa invoice, hindi
> sa draft."

Ituro mo yung filter: This month / This quarter / This year / Custom.

**b. Invoice dashboard** (`/admin/reports/invoice-dashboard`)

> "Ito naman yung tanong ng officer: sino yung may utang. Aging — Current, 1–30, 31–60,
> 61–90, 90+ days. Tapos yung listahan ng may pinakamalaking utang, na pwede mong i-click
> para makita mismo yung invoice."

**c. Trial balance** (`/admin/reports/trial-balance`)

> "Tapos para sa accountant: yung trial balance. Ito yung proof na tama lahat — pantay yung
> debit at credit, hanggang sentimo."

**Punchline — ito yung pinakamalakas na linya sa buong demo:**

> "Tignan niyo, **magkaiba yung dalawang dashboard, at tama yun.** Yung accounting dashboard,
> books yung binabasa. Yung invoice dashboard, documents yung binabasa. Yung draft na invoice,
> trabaho yun na hinahabol ng officer pero hindi pa income. Sinadya naming ihiwalay — kasi pag
> pinagsama mo yan, may magbabasa ng maling number tapos doon siya magde-desisyon."

---

## Mga tanong na siguradong itatanong

**"Paano kung may namali sa naka-post na entry?"**

> "Hindi 'to dine-delete. Rine-reverse. Nandun pa rin yung original at yung pambawi, tapos
> nag-cancel sila sa isa't isa. Ganun talaga sa accounting — kasi pag dinelete mo yung entry
> na na-report mo na sa BIR last month, wala ka nang maipapakita kung bakit nag-iba yung
> number."

**"Makikita ba ng ibang school yung data namin?"**

> "Hindi. May sariling books, sariling chart of accounts, sariling contacts bawat school.
> Naka-built in yan sa system — hindi lang filter na pwedeng makalimutan."

**"Pwede bang i-delete yung invoice?"**

> "Yung draft, oo. Yung approved na, hindi — vino-void yun, tapos kailangan may reason.
> Nakalagay sa document kung bakit."

**"Paano yung BIR numbering?"**

Maging straight ka dito:

> "Yung current numbering, `INV-2026-00001`, sunod-sunod kada taon. Ok na 'to as internal
> reference. Pero kung kailangan niyo ng totoong BIR-authorised series na walang gaps, ibang
> usapan yun, at kailangan muna nating pag-usapan kung anong documents yung authorized kayong
> mag-issue."

**"Kailangan pa ba namin ng accountant?"**

> "Oo. Yung inaalis nito, yung **encoding** — hindi yung judgment. Yung accountant pa rin
> yung magsasabi kung tama yung classification at kung pwede nang i-close yung buwan."

---

## Wag mong gagawin sa demo

| Wag | Bakit |
|---|---|
| Mag-demo sa MindHearts | Walang accounting period — mag-e-error sa first approve |
| Subukang i-delete yung approved invoice | Sinadya yung refusal. Pag hindi mo na-explain, mukhang bug |
| Mag-import ulit ng opening items | May walo nang naka-record. Tatanggi yun hangga't hindi mo vino-void yung mga yun |
| Mangako ng BIR e-filing | Wala pa. Wag mangako ng wala |
| Basta i-click yung Custom sa dashboard | Pumili ka muna ng dates bago mo pindutin yung Apply, kung hindi babalik yun sa fiscal year |

---

## Cheat sheet — yung totoong numbers sa demo data

Sa school 1, as of ngayon:

| | |
|---|---|
| Chart of accounts | 40 accounts, 5 types |
| Contacts | 3 (dagdagan mo kung gusto mo ng mas totoong-buhay na demo) |
| Invoices | 24 — draft 3, approved 5, sent 11, partially paid 3, paid 1, voided 1 |
| Recurring schedules | 3, may 2 nang na-generate na invoice |
| Books opened on | 2026-09-01 |
| Open na periods | July, August, September 2026 |
| **Receivables (ledger)** | **₱1,425,325.37** |
| **Open items** | **8 documents, ₱1,425,325.37 — zero yung difference** |
| Payables (ledger) | ₱752,718.65 |
| Open items sa payables | **Wala pa** |

**May isang bagay na dapat alam mo bago ka matanong:** yung payables, may ₱752,718.65 sa
books pero walang documents na naka-support. Kung tanungin ka kung bakit zero yung isang
side ng reconciliation, yun yung sagot — hindi pa na-upload yung mga unpaid supplier bills.
Kaya naman yun ng parehong importer, hindi pa lang nagagawa.

Kung gusto mong ayusin bago yung demo, mag-upload ka ng open items na `purchase` yung `type`,
tapos aabot sa ₱752,718.65.
