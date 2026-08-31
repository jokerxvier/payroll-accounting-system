<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Pas\Contact;
use App\Models\Pas\ContactStudent;
use App\Models\Pas\Invoice;
use DomainException;

/**
 * Who may be billed, and for whom.
 *
 * These two rules used to live as private methods on `InvoiceRequest`, which
 * was fine while a form was the only way an invoice got made. It is not any
 * more: a recurring schedule generates invoices with no request in sight, and
 * a rule enforced in a FormRequest is a rule the generator does not have.
 *
 * They return a human sentence rather than throwing, because the two callers
 * need different things from a failure. The form turns it into a message under
 * the field; the generator records it against the schedule and moves on to the
 * next family. Neither wants the other's control flow.
 *
 * Every query here leans on `BelongsToTenant` for the `school_id` half of a
 * composite key, so callers must have a tenant current — which for the
 * generator means inside its `makeCurrent()` loop, not outside it.
 */
final class InvoiceBillingRules
{
    /**
     * A sales invoice needs a customer and a bill needs a supplier.
     *
     * Checked rather than left to the database because the failure is not a
     * broken reference — the contact exists, it just is not the kind of
     * counterparty this document is for.
     *
     * @return string|null The reason it cannot be billed, or null if it can.
     */
    public function contactCannotBeBilled(?Contact $contact, string $type): ?string
    {
        if ($contact === null) {
            return null;
        }

        if ($type === Invoice::TYPE_SALES && ! $contact->is_customer) {
            return "{$contact->name} is not marked as a customer, so a sales invoice cannot be raised against them.";
        }

        if ($type !== Invoice::TYPE_SALES && ! $contact->is_supplier) {
            return "{$contact->name} is not marked as a supplier, so a bill cannot be recorded against them.";
        }

        return null;
    }

    /**
     * The chosen payer must actually be responsible for the chosen student.
     *
     * Billing a parent for someone else's child is the mistake the student
     * picker makes possible — it resolves a payer, but the payer Select stays
     * editable, so a stale selection can survive a change of student. On a
     * schedule the same staleness lasts months rather than seconds.
     *
     * @return string|null The reason the pairing is wrong, or null if it holds.
     */
    public function payerIsNotLinkedToStudent(?int $lmsStudentId, ?int $contactId): ?string
    {
        if ($lmsStudentId === null || $contactId === null) {
            return null;
        }

        $linked = ContactStudent::query()
            ->forStudent($lmsStudentId)
            ->where('contact_id', $contactId)
            ->exists();

        return $linked
            ? null
            : 'That contact is not recorded as paying for this student. Pick one of the student\'s linked guardians, or link them first.';
    }

    /**
     * Both rules at once, for a caller that wants an exception.
     *
     * @throws DomainException The first rule that fails, with its sentence.
     */
    public function assert(?Contact $contact, string $type, ?int $lmsStudentId): void
    {
        $reason = $this->contactCannotBeBilled($contact, $type)
            ?? $this->payerIsNotLinkedToStudent($lmsStudentId, $contact?->getKey());

        if ($reason !== null) {
            throw new DomainException($reason);
        }
    }
}
