<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\ContactStudent;
use App\Models\Pas\Invoice;
use Carbon\CarbonImmutable;

/**
 * Validated input → the columns on an invoice header.
 *
 * Its own class because three callers need it and none of them owns it:
 * `CreateInvoiceDraft` (a person drafting, and a schedule generating),
 * `InvoiceController::update()` (a person editing), and nothing else should.
 * Left on the controller it was reachable only through a request.
 *
 * Reads `pas_contact_students` for the student snapshot, so a caller must have
 * a tenant current — `BelongsToTenant` supplies the `school_id` half of that
 * composite key and fails open without it.
 */
final class InvoiceHeaderAttributes
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function fromValidated(array $data): array
    {
        $studentId = isset($data['lms_student_id']) ? (int) $data['lms_student_id'] : null;

        return [
            'type' => $data['type'],
            'contact_id' => (int) $data['contact_id'],
            'lms_student_id' => $studentId,
            // Snapshot, not a join: an invoice is a document, and what it says
            // must not change because a name was corrected in the LMS later.
            'student_name' => $studentId === null
                ? null
                : ContactStudent::query()->forStudent($studentId)->value('student_name'),
            'reference' => $data['reference'] ?? null,
            'issue_date' => CarbonImmutable::parse((string) $data['issue_date']),
            'due_date' => isset($data['due_date'])
                ? CarbonImmutable::parse((string) $data['due_date'])
                : null,
            'is_vat_inclusive' => (bool) $data['is_vat_inclusive'],
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
            'status' => Invoice::STATUS_DRAFT,
        ];
    }
}
