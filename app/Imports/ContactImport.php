<?php

declare(strict_types=1);

namespace App\Imports;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Parses the contact worksheet into per-row figures, changes and refusals.
 *
 * Writes nothing. `parsed()` returns a dataset the controller renders for
 * preview, and only the confirm endpoint applies it — the same split every
 * importer in this app uses, because a bulk change to a register somebody
 * bills against deserves to be read before it happens.
 *
 * **`code` is the join key.** A row whose code already exists updates that
 * contact; a row whose code is new creates one. This is what makes the
 * export/import round trip work — take the register away, fix it, put it
 * back — and it is also the sharp edge: changing a code in the sheet does not
 * rename a contact, it creates a second one. The heading says so, and so does
 * the preview, which marks every row as either an update or a creation.
 *
 * There IS a diff here, unlike {@see OpeningBalanceImport}. An opening balance
 * states figures for a ledger that has none, so "changed from" means nothing;
 * a contact register already exists, and the useful question about a
 * hundred-row upload is which twelve rows actually move.
 */
final class ContactImport implements ToCollection, WithHeadingRow
{
    /**
     * Each row carries: `row_number`, `code`, `contact_id`, `action`
     * (`create` | `update` | `unchanged`), `attributes`, `changes`, `errors`.
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

        // One lookup each for the whole file rather than a query per row.
        $existing = Contact::query()->get()->keyBy(
            fn (Contact $contact): string => mb_strtolower(trim($contact->code)),
        );

        $accounts = ChartOfAccount::query()
            ->get(['id', 'code', 'name', 'type'])
            ->keyBy(fn (ChartOfAccount $account): string => mb_strtolower(trim($account->code)));

        // TINs already taken, so a duplicate is refused on the preview rather
        // than by a unique-constraint stack trace at the end of the import.
        $takenTins = Contact::query()
            ->whereNotNull('tin')
            ->pluck('id', 'tin')
            ->all();

        $seenCodes = [];
        $seenTins = [];
        $rowNumber = 1; // heading is row 1; data starts at row 2

        foreach ($rows as $raw) {
            $rowNumber++;

            if ($this->isBlankRow($raw)) {
                continue;
            }

            $entry = [
                'row_number' => $rowNumber,
                'code' => null,
                'contact_id' => null,
                'name' => null,
                'action' => 'create',
                'attributes' => [],
                'changes' => [],
                'errors' => [],
            ];

            $code = trim((string) ($raw['code_do_not_change'] ?? $raw['code'] ?? ''));

            if ($code === '') {
                $entry['errors'][] = 'code is required. It is what an update is matched on.';
                $this->parsed[] = $entry;

                continue;
            }

            if (mb_strlen($code) > 32) {
                $entry['errors'][] = 'code is longer than 32 characters.';
                $this->parsed[] = $entry;

                continue;
            }

            $entry['code'] = $code;
            $key = mb_strtolower($code);

            if (isset($seenCodes[$key])) {
                $entry['errors'][] = sprintf(
                    'Code %s appears more than once (first on row %d).',
                    $code,
                    $seenCodes[$key],
                );
                $this->parsed[] = $entry;

                continue;
            }

            $seenCodes[$key] = $rowNumber;

            $contact = $existing->get($key);
            $entry['contact_id'] = $contact?->getKey();
            $entry['action'] = $contact === null ? 'create' : 'update';

            $this->readName($entry, $raw['name'] ?? null, $contact);
            $this->readFlags($entry, $raw, $contact);
            $this->readTin($entry, $raw['tin'] ?? null, $contact, $takenTins, $seenTins, $rowNumber);
            $this->readEmail($entry, $raw['email'] ?? null, $contact);
            $this->readText($entry, 'phone', $raw['phone'] ?? null, 40, $contact);
            $this->readText($entry, 'address', $raw['address'] ?? null, 1000, $contact);
            $this->readText($entry, 'notes', $raw['notes'] ?? null, 1000, $contact);
            $this->readAccount($entry, 'receivable_account_id', $raw['receivable_account_code'] ?? null, ChartOfAccount::TYPE_ASSET, $accounts, $contact);
            $this->readAccount($entry, 'payable_account_id', $raw['payable_account_code'] ?? null, ChartOfAccount::TYPE_LIABILITY, $accounts, $contact);

            // A row that names neither role bills against nothing. Checked
            // after both flags are read so the message is about the pair.
            $isCustomer = $entry['attributes']['is_customer']
                ?? ($contact === null ? false : $contact->is_customer);
            $isSupplier = $entry['attributes']['is_supplier']
                ?? ($contact === null ? false : $contact->is_supplier);

            if (! $isCustomer && ! $isSupplier) {
                $entry['errors'][] = 'A contact has to be a customer, a supplier, or both.';
            }

            // An update whose every column matches what is already stored is
            // reported rather than applied — most rows in a round-tripped
            // export are untouched, and saying so is what makes the twelve
            // that did move findable.
            if ($entry['action'] === 'update' && $entry['changes'] === []) {
                $entry['action'] = 'unchanged';
            }

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
    private function readName(array &$entry, mixed $raw, ?Contact $contact): void
    {
        $name = trim((string) ($raw ?? ''));

        if ($name === '') {
            $entry['errors'][] = 'name is required.';

            return;
        }

        if (mb_strlen($name) > 160) {
            $entry['errors'][] = 'name is longer than 160 characters.';

            return;
        }

        $entry['name'] = $name;
        $this->set($entry, 'name', $name, $contact?->name);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>|Collection<string, mixed>  $raw
     */
    private function readFlags(array &$entry, mixed $raw, ?Contact $contact): void
    {
        foreach (['is_customer', 'is_supplier', 'is_active'] as $field) {
            $value = $raw[$field] ?? null;
            $text = mb_strtolower(trim((string) ($value ?? '')));

            if ($text === '') {
                // Blank means "leave it alone" on an update, and a sensible
                // default on a create: active, and neither role until stated.
                if ($contact === null) {
                    $this->set($entry, $field, $field === 'is_active', null);
                }

                continue;
            }

            $truthy = in_array($text, ['yes', 'y', 'true', '1'], true);
            $falsy = in_array($text, ['no', 'n', 'false', '0'], true);

            if (! $truthy && ! $falsy) {
                $entry['errors'][] = sprintf('%s must be yes or no.', $field);

                continue;
            }

            $this->set($entry, $field, $truthy, $contact?->{$field});
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, int>  $takenTins
     * @param  array<string, int>  $seenTins
     */
    private function readTin(
        array &$entry,
        mixed $raw,
        ?Contact $contact,
        array $takenTins,
        array &$seenTins,
        int $rowNumber,
    ): void {
        $text = trim((string) ($raw ?? ''));

        if ($text === '') {
            $this->set($entry, 'tin', null, $contact?->tin);

            return;
        }

        // Punctuation stripped exactly as `ContactRequest` strips it, so a TIN
        // typed 123-456-789 in a spreadsheet is the same TIN it would be if
        // typed into the form.
        $digits = preg_replace('/\D+/', '', $text) ?? '';

        if (mb_strlen($digits) < 9 || mb_strlen($digits) > 12) {
            $entry['errors'][] = 'tin must be 9 to 12 digits.';

            return;
        }

        if (isset($seenTins[$digits])) {
            $entry['errors'][] = sprintf(
                'TIN %s appears more than once (first on row %d).',
                $digits,
                $seenTins[$digits],
            );

            return;
        }

        $seenTins[$digits] = $rowNumber;

        $owner = $takenTins[$digits] ?? null;

        if ($owner !== null && $owner !== $contact?->getKey()) {
            $entry['errors'][] = sprintf(
                'TIN %s already belongs to another contact.',
                $digits,
            );

            return;
        }

        $this->set($entry, 'tin', $digits, $contact?->tin);
    }

    /** @param array<string, mixed> $entry */
    private function readEmail(array &$entry, mixed $raw, ?Contact $contact): void
    {
        $email = trim((string) ($raw ?? ''));

        if ($email === '') {
            $this->set($entry, 'email', null, $contact?->email);

            return;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 160) {
            $entry['errors'][] = sprintf('"%s" is not an email address.', $email);

            return;
        }

        $this->set($entry, 'email', $email, $contact?->email);
    }

    /** @param array<string, mixed> $entry */
    private function readText(
        array &$entry,
        string $field,
        mixed $raw,
        int $max,
        ?Contact $contact,
    ): void {
        $text = trim((string) ($raw ?? ''));

        if ($text === '') {
            $this->set($entry, $field, null, $contact?->{$field});

            return;
        }

        if (mb_strlen($text) > $max) {
            $entry['errors'][] = sprintf('%s is longer than %d characters.', $field, $max);

            return;
        }

        $this->set($entry, $field, $text, $contact?->{$field});
    }

    /**
     * A control-account override, referenced by chart CODE.
     *
     * @param  array<string, mixed>  $entry
     * @param  Collection<string, ChartOfAccount>  $accounts
     */
    private function readAccount(
        array &$entry,
        string $field,
        mixed $raw,
        string $expectedType,
        Collection $accounts,
        ?Contact $contact,
    ): void {
        $code = trim((string) ($raw ?? ''));

        if ($code === '') {
            $this->set($entry, $field, null, $contact?->{$field});

            return;
        }

        $account = $accounts->get(mb_strtolower($code));

        if (! $account instanceof ChartOfAccount) {
            $entry['errors'][] = sprintf(
                'No account with code %s exists in this school\'s chart of accounts.',
                $code,
            );

            return;
        }

        // A receivable that is not an asset, or a payable that is not a
        // liability, would put a contact's balance on the wrong side of the
        // books for every document ever addressed to them.
        if ($account->type !== $expectedType) {
            $entry['errors'][] = sprintf(
                'Account %s is %s account. A %s override has to be %s.',
                $code,
                // "an equity account", "a liability account" — the article is
                // computed because the type is, and a message that reads "is
                // a a liability account" undermines the one thing it is for.
                in_array($account->type, ['asset', 'expense', 'equity'], true)
                    ? 'an '.$account->type
                    : 'a '.$account->type,
                $field === 'receivable_account_id' ? 'receivable' : 'payable',
                $expectedType === ChartOfAccount::TYPE_ASSET ? 'an asset' : 'a liability',
            );

            return;
        }

        $this->set($entry, $field, (int) $account->getKey(), $contact?->{$field});
    }

    /**
     * Records the value to write, and the change if it moves.
     *
     * @param  array<string, mixed>  $entry
     */
    private function set(array &$entry, string $field, mixed $value, mixed $current): void
    {
        $entry['attributes'][$field] = $value;

        // Loose comparison on purpose for the boolean columns: what comes back
        // from the model is a real bool and what the sheet yields is too, but
        // an integer 0/1 from a legacy row would otherwise read as a change
        // every single import.
        $unchanged = is_bool($value)
            ? (bool) $current === $value
            : $current === $value;

        if (! $unchanged) {
            $entry['changes'][$field] = ['from' => $current, 'to' => $value];
        }
    }

    /**
     * @param  array<string, mixed>|Collection<string, mixed>  $raw
     */
    private function isBlankRow(mixed $raw): bool
    {
        foreach (['code_do_not_change', 'code', 'name', 'tin', 'email', 'phone'] as $key) {
            $value = $raw[$key] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
