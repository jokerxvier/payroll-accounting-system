<?php

declare(strict_types=1);

namespace App\Exports;

use App\Imports\ContactImport;
use App\Models\Pas\Contact;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The contact register, in the shape the importer reads back.
 *
 * Export and import share one set of headings on purpose: the useful thing a
 * person does with a contact list is take it away, fix a hundred spellings or
 * add fifty families in a spreadsheet, and put it back. That only works if the
 * file that comes out is a file that goes in.
 *
 * `code` is the join key — {@see ContactImport} matches on it and
 * updates the row it finds, so changing a code in the sheet does not rename a
 * contact, it creates a second one. That warning is in the heading itself
 * rather than a note beside the download, because the sheet outlives the page.
 *
 * Control accounts travel as CODES, never ids. An id means nothing to the
 * person editing the file and would not survive being carried to another
 * school's chart; `1200` is the same account to everyone who reads a trial
 * balance.
 *
 * The LMS keys (`lms_parent_id`, `lms_student_id`) are deliberately omitted.
 * They are the guardian import's dedupe key, and a hand-edited spreadsheet is
 * the wrong place to rewire a contact to a different family.
 */
final class ContactExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @param  string|null  $search  The list's active search, so what a person
     *                               exports is what they were looking at.
     * @param  string|null  $role  `customer`, `supplier`, or null for both.
     */
    public function __construct(
        private readonly ?string $search = null,
        private readonly ?string $role = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'code (do not change)',
            'name',
            'is_customer',
            'is_supplier',
            'tin',
            'email',
            'phone',
            'address',
            'receivable_account_code',
            'payable_account_code',
            'is_active',
            'notes',
        ];
    }

    /**
     * @return Collection<int, array<string, string|null>>
     */
    public function collection(): Collection
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = [];

        $contacts = Contact::query()
            ->with(['receivableAccount:id,code', 'payableAccount:id,code'])
            ->when(
                $this->search !== null && $this->search !== '',
                fn ($query) => $query->matching((string) $this->search),
            )
            ->when($this->role === 'customer', fn ($query) => $query->customers())
            ->when($this->role === 'supplier', fn ($query) => $query->suppliers())
            ->orderBy('name')
            ->get();

        foreach ($contacts as $contact) {
            $rows[] = [
                'code' => $contact->code,
                'name' => $contact->name,
                // "yes"/"no" rather than 1/0: a spreadsheet renders a bare 1
                // as a number and the next person to open the file cannot
                // tell what it means. The importer reads either.
                'is_customer' => $contact->is_customer ? 'yes' : 'no',
                'is_supplier' => $contact->is_supplier ? 'yes' : 'no',
                'tin' => $contact->tin,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'address' => $contact->address,
                'receivable_account_code' => $contact->receivableAccount?->code,
                'payable_account_code' => $contact->payableAccount?->code,
                'is_active' => $contact->is_active ? 'yes' : 'no',
                'notes' => $contact->notes,
            ];
        }

        return new Collection($rows);
    }
}
