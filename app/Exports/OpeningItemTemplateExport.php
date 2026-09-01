<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\Pas\Contact;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * The open-items worksheet: the unpaid documents behind the opening AR and AP.
 *
 * Unlike {@see OpeningBalanceTemplateExport}, which lists every account that
 * can carry a balance and asks for figures, this one cannot know the rows in
 * advance — the documents live in the school's previous system. So it ships
 * the school's contacts as a reference block instead, because the one field a
 * client cannot guess is how this system spells a payer's name.
 *
 * A blank sheet with only headings would be technically sufficient and
 * practically useless: the import matches on contact name, and a file typed
 * against the old system's spelling fails every row.
 */
final class OpeningItemTemplateExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'type',
            'contact_name',
            'document_number',
            'issue_date',
            'due_date',
            'total_amount',
            'amount_already_paid',
            'student_name',
        ];
    }

    /**
     * One example row, then every contact as a reference.
     *
     * The example is spelled out rather than described in a note because a
     * date format is far easier to copy than to read about, and `type` has
     * exactly two legal values that nothing else on the sheet reveals.
     *
     * @return Collection<int, array<string, string|null>>
     */
    public function collection(): Collection
    {
        /** @var list<array<string, string|null>> $rows */
        $rows = [$this->row(
            'sales',
            '(delete this row) Example Family',
            'INV-2025-0042',
            '2026-05-31',
            '2026-06-30',
            '12500.00',
            '2500.00',
            'Juan Dela Cruz',
        )];

        foreach (Contact::query()->orderBy('name')->get(['name', 'is_customer', 'is_supplier']) as $contact) {
            // Pre-filled so each row is a working starting point once the
            // reader deletes the ones they do not need.
            $rows[] = $this->row(
                $contact->is_customer ? 'sales' : 'purchase',
                $contact->name,
            );
        }

        return new Collection($rows);
    }

    /**
     * @return array<string, string|null>
     */
    private function row(
        string $type,
        string $contactName,
        ?string $documentNumber = null,
        ?string $issueDate = null,
        ?string $dueDate = null,
        ?string $totalAmount = null,
        ?string $amountAlreadyPaid = null,
        ?string $studentName = null,
    ): array {
        return [
            'type' => $type,
            'contact_name' => $contactName,
            'document_number' => $documentNumber,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'total_amount' => $totalAmount,
            'amount_already_paid' => $amountAlreadyPaid,
            'student_name' => $studentName,
        ];
    }
}
