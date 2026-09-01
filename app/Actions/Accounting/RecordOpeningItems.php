<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Imports\OpeningBalanceImport;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\InvoiceLine;
use App\Models\Pas\School;
use App\Services\Accounting\ControlAccountResolver;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Phase 5 Slice 9 — the sub-ledger behind the cutover snapshot.
 *
 * {@see PostOpeningBalances} states what a school's books said in total: AR was
 * ₱326,000. This states *which unpaid invoices make up that figure*, so an
 * officer has documents to chase and the control account has something to
 * reconcile against.
 *
 * **An open item never posts to the ledger, and that is the whole design.**
 * The snapshot already debited AR for the total. Posting each historical
 * invoice again would either double the control balance, or — if it credited
 * income the way a live invoice does — report last year's trading as this
 * period's revenue, which is the precise thing {@see OpeningBalanceImport}
 * refuses income and expense rows for. So these documents carry
 * `journal_entry_id = null` for good, flagged `is_opening_item` so nothing
 * mistakes them for drafts awaiting approval.
 *
 * Everything downstream already works without changes, which is the strongest
 * evidence the shape is right: `scopeOutstanding()` filters on status and the
 * paid/total comparison, so open items flow straight into the ageing buckets;
 * `PaymentPostingService` credits AR from the *payment's* contact rather than
 * the invoice's entry, so a receipt against one correctly draws the control
 * balance down; and `VoidInvoice` already tolerates a null entry.
 *
 * Three refusals it adds on top of the ordinary rules:
 *
 *   1. **The books must be open.** Without `books_opened_on` there is no
 *      cutover to hang these on and nothing to reconcile to.
 *   2. **Nothing dated after the cutover.** A document issued after the books
 *      opened here belongs in the live system, raised the ordinary way so it
 *      posts.
 *   3. **One set per school.** A second import would double the sub-ledger
 *      against an unchanged control account.
 */
final class RecordOpeningItems
{
    public function __construct(
        private readonly ControlAccountResolver $controlAccounts,
    ) {}

    /**
     * @param  list<array{
     *     type: string,
     *     contact_id: int,
     *     number: ?string,
     *     issue_date: string,
     *     due_date: ?string,
     *     total_centavos: int,
     *     amount_paid_centavos: int,
     *     student_name: ?string,
     * }>  $items
     * @return EloquentCollection<int, Invoice>
     *
     * @throws DomainException Books not open, nothing to record, a date after
     *                         cutover, or open items already recorded.
     */
    public function execute(array $items, int $actorUserId): EloquentCollection
    {
        $school = $this->currentSchool();
        $cutover = $this->assertBooksAreOpen($school);

        if ($items === []) {
            throw new DomainException(
                'There is nothing to record. Fill in at least one open item.'
            );
        }

        $this->assertNoExistingItems();

        return DB::transaction(function () use ($items, $cutover, $actorUserId): EloquentCollection {
            /** @var EloquentCollection<int, Invoice> $recorded */
            $recorded = new EloquentCollection;

            // Loaded once for the whole file. The resolver reads a contact's
            // AR/AP override before falling back to the system account, and a
            // hundred-row import would otherwise re-query per line.
            $contacts = Contact::query()
                ->whereKey(array_column($items, 'contact_id'))
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $issueDate = CarbonImmutable::parse($item['issue_date'])->startOfDay();

                if ($issueDate->greaterThan($cutover)) {
                    throw new DomainException(sprintf(
                        'Document %s is dated %s, after the books were opened on %s. Raise it in the normal way so it posts to the ledger.',
                        $item['number'] ?? '(unnumbered)',
                        $issueDate->toDateString(),
                        $cutover->toDateString(),
                    ));
                }

                $recorded->push(
                    $this->record($item, $issueDate, $cutover, $contacts, $actorUserId)
                );
            }

            return $recorded;
        });
    }

    /**
     * @param  array{
     *     type: string,
     *     contact_id: int,
     *     number: ?string,
     *     issue_date: string,
     *     due_date: ?string,
     *     total_centavos: int,
     *     amount_paid_centavos: int,
     *     student_name: ?string,
     * }  $item
     * @param  EloquentCollection<int, Contact>  $contacts
     */
    private function record(
        array $item,
        CarbonImmutable $issueDate,
        CarbonImmutable $cutover,
        EloquentCollection $contacts,
        int $actorUserId,
    ): Invoice {
        $originalTotal = $item['total_centavos'];
        $collectedBefore = $item['amount_paid_centavos'];

        /*
         * The document is recorded at its BROUGHT-FORWARD BALANCE, not at the
         * figure it was originally issued for.
         *
         * The AR control account states what is still owed at cutover. A
         * ₱142,000 invoice with ₱42,000 already collected contributes
         * ₱100,000 to that, so recording the gross would overstate the
         * sub-ledger by everything the school collected before it moved — and
         * the reconciliation would report a difference that is really just
         * last year's receipts.
         *
         * Carrying the gross and putting the receipts in
         * `amount_paid_centavos` would tie on the day of the import and drift
         * from then on: that column grows with every payment taken here,
         * while the control balance is read as at a cutover that never moves.
         * The brought-forward figure is fixed, so the two agree for good.
         *
         * What is lost — the original face value and the pre-cutover receipt —
         * goes into `notes`, because it is provenance a person may want to
         * read and nothing computes from it.
         */
        $broughtForward = $originalTotal - $collectedBefore;

        $invoice = Invoice::create([
            'type' => $item['type'],
            'contact_id' => $item['contact_id'],
            'student_name' => $item['student_name'],
            // As given, never allocated. A historical document's serial is
            // the one it was actually issued under; drawing a fresh number
            // from the live counter would renumber a document the client's
            // customer already holds a copy of.
            'number' => $item['number'],
            'issue_date' => $issueDate,
            'due_date' => $item['due_date'] === null
                ? null
                : CarbonImmutable::parse($item['due_date'])->startOfDay(),
            // `sent`, never `partially_paid`: nothing has been received
            // against this document IN THIS SYSTEM. The earlier receipt
            // belongs to the books it came from and is already netted off the
            // balance below.
            'status' => Invoice::STATUS_SENT,
            'is_vat_inclusive' => false,
            // The whole figure sits in the exempt bucket rather than being
            // split. The VAT on a historical document was accounted for in
            // the books it came from, and re-declaring it here would put it
            // in an Output VAT account that the snapshot has already stated.
            'vat_exempt_sales_centavos' => $broughtForward,
            'total_centavos' => $broughtForward,
            'amount_paid_centavos' => 0,
            'notes' => $this->provenance($item, $originalTotal, $collectedBefore),
            // Null for good — see the class docblock.
            'journal_entry_id' => null,
            'is_opening_item' => true,
            // Stamped from the cutover, not from now(). The document was
            // approved and sent by whoever ran the client's previous books;
            // what these record is the date it entered these ones.
            'approved_at' => $cutover,
            'approved_by_user_id' => $actorUserId,
            // `sent_at` matters beyond bookkeeping: InvoiceBalanceService
            // derives status from the paid amount and falls back to `sent`
            // only when this is set. Without it, voiding a payment against an
            // open item would quietly demote a document the school has been
            // chasing for months to `approved`. `sent_to` stays null — it
            // went out from the previous system, to an address this one never
            // saw.
            'sent_at' => $cutover,
        ]);

        $contact = $contacts->get($item['contact_id']);

        InvoiceLine::create([
            'invoice_id' => $invoice->getKey(),
            'line_number' => 1,
            'description' => 'Balance brought forward',
            'quantity' => '1',
            'unit_price_centavos' => $broughtForward,
            // The control account, because that is the account this balance
            // actually sits in. A single synthetic line rather than the
            // original document's breakdown: the old system holds that
            // detail, and inventing an income split here would imply revenue
            // this ledger never recognised.
            'account_id' => (int) $this->controlAccounts
                ->resolve($contact, $invoice->isSales())
                ->getKey(),
            'tax_rate_id' => null,
            'line_net_centavos' => $broughtForward,
            'line_tax_centavos' => 0,
        ]);

        return $invoice;
    }

    /**
     * Where the balance came from, in words.
     *
     * Recording the brought-forward figure loses the document's original face
     * value, and somebody reconciling against the client's old system will
     * want it. Nothing computes from this — it is here to be read.
     *
     * @param  array{number: ?string, ...}  $item
     */
    private function provenance(array $item, int $originalTotal, int $collectedBefore): string
    {
        $reference = $item['number'] ?? 'An unnumbered document';

        if ($collectedBefore === 0) {
            return sprintf('%s, carried in unpaid from the previous books.', $reference);
        }

        return sprintf(
            '%s was issued for %s, of which %s had been received before the cutover. The balance brought forward is %s.',
            $reference,
            $this->peso($originalTotal),
            $this->peso($collectedBefore),
            $this->peso($originalTotal - $collectedBefore),
        );
    }

    /** Centavos as the note should read them, never as a float. */
    private function peso(int $centavos): string
    {
        return '₱'.number_format(
            (float) Money::fromCentavos($centavos)->toDecimalString(),
            2,
        );
    }

    /**
     * @throws DomainException
     */
    private function currentSchool(): School
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof School) {
            throw new DomainException('No school is current, so open items cannot be recorded.');
        }

        return $tenant;
    }

    /**
     * @throws DomainException
     */
    private function assertBooksAreOpen(School $school): CarbonImmutable
    {
        // Read from the row, not from the bound tenant instance. Spatie binds
        // ONE School object for the process, and `PostOpeningBalances` stamps
        // `books_opened_on` through the query builder — so anything holding
        // that instance goes on seeing a null long after the books were
        // opened. Getting this wrong would refuse a perfectly good import.
        $cutover = School::query()
            ->whereKey($school->getKey())
            ->value('books_opened_on');

        if ($cutover === null) {
            throw new DomainException(
                'These books have not been opened yet. Import the opening balances first — open items are the documents behind the receivable that snapshot states.'
            );
        }

        // Parsed from whatever the column hands back — Eloquent applies the
        // date cast through `value()`, but a raw string is just as valid an
        // answer and both mean the same day.
        return CarbonImmutable::parse(
            $cutover instanceof \DateTimeInterface
                ? $cutover->format('Y-m-d')
                : (string) $cutover
        )->startOfDay();
    }

    /**
     * @throws DomainException
     */
    private function assertNoExistingItems(): void
    {
        $existing = Invoice::query()->openingItems()->count();

        if ($existing > 0) {
            throw new DomainException(sprintf(
                '%d open item%s already recorded. Void them before importing a new set — a second import would double the sub-ledger against an unchanged control account.',
                $existing,
                $existing === 1 ? ' is' : 's are',
            ));
        }
    }
}
