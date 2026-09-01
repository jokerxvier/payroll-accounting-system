<?php

declare(strict_types=1);

namespace App\Imports;

use App\Actions\Accounting\RecordOpeningItems;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\School;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Spatie\Multitenancy\Models\Tenant;

/**
 * Parses the open-items worksheet into per-row figures and per-row refusals.
 *
 * Writes nothing, exactly like {@see OpeningBalanceImport}: `parsed()` returns
 * a dataset the controller renders for preview, and only the confirm endpoint
 * — through {@see RecordOpeningItems} — creates
 * anything. Errors accumulate per row rather than aborting the file, because
 * somebody transcribing a hundred unpaid invoices wants every problem in one
 * pass.
 *
 * There is no diff here for the same reason the opening-balance importer has
 * none: these documents do not exist in this system yet, so "changed from" is
 * meaningless.
 */
final class OpeningItemImport implements ToCollection, WithHeadingRow
{
    use Importable;

    /**
     * Each row carries: `row_number`, `type`, `contact_id`, `contact_name`,
     * `number`, `issue_date`, `due_date`, `total_centavos`,
     * `amount_paid_centavos`, `student_name`, `warnings`, `errors`.
     *
     * Typed loosely because the per-row helpers take the entry by reference
     * and append to it; a sealed array shape cannot survive that.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $parsed = [];

    /**
     * @param  Collection<int, Collection<string, mixed>>  $rows
     */
    public function collection(Collection $rows): void
    {
        $this->parsed = [];

        // One lookup for the file. Keyed by lowercased name because that is
        // what a person transcribing from another system has in front of
        // them, and because matching on exact case would fail a file that is
        // otherwise perfectly correct.
        $contacts = Contact::query()
            ->get(['id', 'name', 'is_customer', 'is_supplier'])
            ->keyBy(fn ($contact): string => mb_strtolower(trim((string) $contact->name)));

        // Numbers already issued here. A historical serial that collides with
        // one of these trips `pas_inv_school_type_number_unq` at insert, and
        // a unique-constraint stack trace at the end of a hundred-row import
        // is a poor way to learn that two documents share a number.
        $existingNumbers = Invoice::query()
            ->whereNotNull('number')
            ->get(['type', 'number'])
            ->groupBy('type')
            ->map(fn (Collection $group): array => $group
                ->pluck('number')
                ->map(fn ($n): string => mb_strtolower((string) $n))
                ->flip()
                ->all())
            ->all();

        $cutover = $this->cutoverDate();
        $seenNumbers = [];
        $rowNumber = 1; // heading is row 1; data starts at row 2

        foreach ($rows as $raw) {
            $rowNumber++;

            if ($this->isBlankRow($raw)) {
                continue;
            }

            $entry = [
                'row_number' => $rowNumber,
                'type' => null,
                'contact_id' => null,
                'contact_name' => null,
                'number' => null,
                'issue_date' => null,
                'due_date' => null,
                'total_centavos' => 0,
                'amount_paid_centavos' => 0,
                'student_name' => null,
                'warnings' => [],
                'errors' => [],
            ];

            $this->readType($entry, $raw['type'] ?? null);
            $this->readContact($entry, $raw['contact_name'] ?? null, $contacts);
            $this->readNumber($entry, $raw['document_number'] ?? null, $existingNumbers, $seenNumbers, $rowNumber);
            $this->readDates($entry, $raw['issue_date'] ?? null, $raw['due_date'] ?? null, $cutover);
            $this->readAmounts($entry, $raw['total_amount'] ?? null, $raw['amount_already_paid'] ?? null);

            $studentName = trim((string) ($raw['student_name'] ?? ''));
            $entry['student_name'] = $studentName === '' ? null : $studentName;

            $this->parsed[] = $entry;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parsed(): array
    {
        return $this->parsed;
    }

    /** @param array<string, mixed> $entry */
    private function readType(array &$entry, mixed $raw): void
    {
        $type = mb_strtolower(trim((string) ($raw ?? '')));

        if ($type === '') {
            $entry['errors'][] = 'type is required — write either sales or purchase.';

            return;
        }

        if (! in_array($type, [Invoice::TYPE_SALES, Invoice::TYPE_PURCHASE], true)) {
            $entry['errors'][] = sprintf(
                '"%s" is not a document type. Write sales for money owed to the school, or purchase for money the school owes.',
                $type,
            );

            return;
        }

        $entry['type'] = $type;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Collection<string, Contact>  $contacts
     */
    private function readContact(array &$entry, mixed $raw, Collection $contacts): void
    {
        $name = trim((string) ($raw ?? ''));

        if ($name === '') {
            $entry['errors'][] = 'contact_name is required.';

            return;
        }

        $entry['contact_name'] = $name;
        $contact = $contacts->get(mb_strtolower($name));

        if ($contact === null) {
            $entry['errors'][] = sprintf(
                'No contact named "%s". Add them under Contacts first, or correct the spelling to match.',
                $name,
            );

            return;
        }

        $entry['contact_id'] = (int) $contact->getKey();

        // A warning rather than a refusal: the flags describe how a contact is
        // normally used, and a supplier who once bought something is unusual
        // rather than wrong.
        $wrongWay = $entry['type'] === Invoice::TYPE_SALES
            ? ! $contact->is_customer
            : ! $contact->is_supplier;

        if ($entry['type'] !== null && $wrongWay) {
            $entry['warnings'][] = sprintf(
                '%s is not marked as a %s.',
                $name,
                $entry['type'] === Invoice::TYPE_SALES ? 'customer' : 'supplier',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, array<string, int>>  $existingNumbers
     * @param  array<string, int>  $seenNumbers
     */
    private function readNumber(
        array &$entry,
        mixed $raw,
        array $existingNumbers,
        array &$seenNumbers,
        int $rowNumber,
    ): void {
        $number = trim((string) ($raw ?? ''));

        if ($number === '') {
            // Allowed. A client whose old system numbered nothing still has
            // a receivable, and the column is nullable.
            return;
        }

        if (mb_strlen($number) > 32) {
            $entry['errors'][] = 'document_number is longer than 32 characters.';

            return;
        }

        $entry['number'] = $number;

        $key = mb_strtolower($entry['type'].'|'.$number);

        if (isset($seenNumbers[$key])) {
            $entry['errors'][] = sprintf(
                'Document number %s appears more than once (first on row %d).',
                $number,
                $seenNumbers[$key],
            );

            return;
        }

        $seenNumbers[$key] = $rowNumber;

        if ($entry['type'] !== null
            && isset($existingNumbers[$entry['type']][mb_strtolower($number)])) {
            $entry['errors'][] = sprintf(
                'Document number %s is already used by an invoice in this system.',
                $number,
            );

            return;
        }

        // Not an error. Importing a serial in this system's own format is
        // correct — reissuing it later would be worse — but it silently moves
        // the live counter past it, and that is worth knowing before it
        // happens rather than after.
        if (preg_match('/^(INV|BILL)-\d{4}-\d{5}$/i', $number) === 1) {
            $entry['warnings'][] = sprintf(
                '%s matches this system\'s own numbering, so the next invoice of that year will continue after it.',
                $number,
            );
        }
    }

    /** @param array<string, mixed> $entry */
    private function readDates(
        array &$entry,
        mixed $rawIssue,
        mixed $rawDue,
        ?CarbonImmutable $cutover,
    ): void {
        $issue = $this->date($entry, 'issue_date', $rawIssue);

        if ($issue === null) {
            $entry['errors'][] = 'issue_date is required.';
        } else {
            $entry['issue_date'] = $issue->toDateString();

            if ($cutover !== null && $issue->greaterThan($cutover)) {
                $entry['errors'][] = sprintf(
                    'Issued %s, after the books were opened on %s. Raise that one in the normal way so it posts to the ledger.',
                    $issue->toDateString(),
                    $cutover->toDateString(),
                );
            }
        }

        // Optional on purpose. `ReceivablesService` files an invoice with no
        // due date under Current rather than Overdue, because absence means
        // nobody agreed a deadline.
        $due = $this->date($entry, 'due_date', $rawDue);

        if ($due !== null) {
            $entry['due_date'] = $due->toDateString();

            if ($issue !== null && $due->lessThan($issue)) {
                $entry['errors'][] = 'due_date falls before issue_date.';
            }
        }
    }

    /** @param array<string, mixed> $entry */
    private function date(array &$entry, string $field, mixed $raw): ?CarbonImmutable
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        // Excel hands back a serial number for a real date cell rather than
        // a string, so the numeric branch is the normal case for a file
        // filled in with the date picker rather than typed as text.
        if (is_numeric($value)) {
            return CarbonImmutable::create(1899, 12, 30)
                ?->addDays((int) $value)
                ?->startOfDay();
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (InvalidFormatException) {
            $entry['errors'][] = sprintf('%s is not a date this can read. Use YYYY-MM-DD.', $field);

            return null;
        }
    }

    /** @param array<string, mixed> $entry */
    private function readAmounts(array &$entry, mixed $rawTotal, mixed $rawPaid): void
    {
        $total = $this->centavos($entry, 'total_amount', $rawTotal);
        $paid = $this->centavos($entry, 'amount_already_paid', $rawPaid);

        if ($total === null || $paid === null) {
            return;
        }

        if ($total === 0) {
            $entry['errors'][] = 'total_amount is required and must be more than zero.';

            return;
        }

        if ($paid > $total) {
            $entry['errors'][] = 'amount_already_paid is more than total_amount.';

            return;
        }

        if ($paid === $total) {
            $entry['errors'][] = 'This document is fully paid, so it is not an open item. Leave settled history in the previous system.';

            return;
        }

        $entry['total_centavos'] = $total;
        $entry['amount_paid_centavos'] = $paid;
    }

    /**
     * Decimal pesos to integer centavos.
     *
     * Lifted from {@see OpeningBalanceImport::centavos()} deliberately,
     * including the `bcmul` rather than a float round-trip: this file is
     * nothing but money, and 8.35 becoming 834 centavos is the failure both
     * importers exist to avoid.
     *
     * @param  array<string, mixed>  $entry
     * @return int|null Null when the cell could not be read, which is distinct
     *                  from a legitimate zero.
     */
    private function centavos(array &$entry, string $field, mixed $rawValue): ?int
    {
        if ($rawValue === null || trim((string) $rawValue) === '') {
            return 0;
        }

        $value = str_replace([',', ' ', '₱'], '', trim((string) $rawValue));

        if (! is_numeric($value)) {
            $entry['errors'][] = sprintf('%s must be a number.', $field);

            return null;
        }

        if (bccomp($value, '0', 2) < 0) {
            $entry['errors'][] = sprintf('%s cannot be negative.', $field);

            return null;
        }

        return (int) bcmul($value, '100', 0);
    }

    /**
     * @param  array<string, mixed>|Collection<string, mixed>  $raw
     */
    private function isBlankRow(mixed $raw): bool
    {
        foreach ([
            'type',
            'contact_name',
            'document_number',
            'issue_date',
            'due_date',
            'total_amount',
            'amount_already_paid',
        ] as $key) {
            $value = $raw[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function cutoverDate(): ?CarbonImmutable
    {
        $tenant = Tenant::current();

        if (! $tenant instanceof School) {
            return null;
        }

        // From the row, not the bound tenant instance. Spatie holds one
        // School object for the process and `PostOpeningBalances` stamps
        // `books_opened_on` through the query builder, so the instance can
        // still read null after the books were opened — and the row that
        // check exists to catch would then sail through.
        $openedOn = School::query()->whereKey($tenant->getKey())->value('books_opened_on');

        if ($openedOn === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $openedOn instanceof \DateTimeInterface
                ? $openedOn->format('Y-m-d')
                : (string) $openedOn
        )->startOfDay();
    }
}
